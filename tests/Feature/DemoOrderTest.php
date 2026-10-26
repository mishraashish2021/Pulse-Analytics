<?php

namespace Tests\Feature;

use App\Jobs\SendAnalyticsEvents;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DemoOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['analytics.public_key' => 'pk_test', 'analytics.secret_key' => 'sk_test']);
    }

    public function test_demo_pages_render_with_the_sdk_installed(): void
    {
        foreach (['/', '/menu', '/about'] as $path) {
            $this->get($path)->assertOk()->assertSee('js/pulse.js', false)->assertSee('data-key="pk_test"', false);
        }
    }

    public function test_placing_an_order_stores_it_and_queues_a_server_event(): void
    {
        Queue::fake();

        $response = $this->withUnencryptedCookie('pa_aid', 'anon-123')->postJson('/orders', [
            'name' => 'Asha',
            'email' => 'Asha@Example.com',
            'items' => [['id' => 'burger', 'qty' => 2], ['id' => 'lemonade', 'qty' => 1]],
            // client-supplied price must be ignored
            'total_cents' => 1,
        ])->assertStatus(201);

        $order = Order::first();
        $this->assertSame(1099 * 2 + 399, $order->total_cents);
        $response->assertJson(['customer_id' => $order->customer_id]);

        Queue::assertPushed(SendAnalyticsEvents::class, function (SendAnalyticsEvents $job) use ($order) {
            $event = $job->events[0];

            return $event['name'] === 'order_created'
                && $event['anonymous_id'] === 'anon-123'
                && $event['user_id'] === $order->customer_id
                && $event['properties']['total_cents'] === 1099 * 2 + 399
                && $event['properties']['item_count'] === 3
                && ! str_contains(json_encode($event), 'example.com'); // no email leaks to analytics
        });
    }

    public function test_same_email_maps_to_the_same_customer_id_case_insensitively(): void
    {
        Queue::fake();
        $payload = ['name' => 'A', 'items' => [['id' => 'fries', 'qty' => 1]]];

        $a = $this->postJson('/orders', $payload + ['email' => 'Asha@Example.com'])->json('customer_id');
        $b = $this->postJson('/orders', $payload + ['email' => 'asha@example.com'])->json('customer_id');

        $this->assertSame($a, $b);
    }

    public function test_unknown_menu_items_are_rejected(): void
    {
        Queue::fake();

        $this->postJson('/orders', [
            'name' => 'Asha', 'email' => 'a@b.com', 'items' => [['id' => 'unicorn', 'qty' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
        Queue::assertNothingPushed();
    }

    public function test_order_page_uses_an_unguessable_id(): void
    {
        Queue::fake();
        $url = $this->postJson('/orders', [
            'name' => 'Asha', 'email' => 'a@b.com', 'items' => [['id' => 'salad', 'qty' => 1]],
        ])->json('redirect');

        $this->get($url)->assertOk()->assertSee('Thanks, Asha');
        $this->get('/orders/1')->assertNotFound();
    }
}
