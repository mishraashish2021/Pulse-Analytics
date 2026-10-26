<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/events';

    private function event(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'name' => 'page_view',
            'anonymous_id' => 'anon-1',
            'session_id' => 'sess-1',
            'url' => 'http://localhost/menu',
            'path' => '/menu',
            'timestamp' => now()->toIso8601String(),
        ], $overrides);
    }

    public function test_rejects_requests_without_a_valid_key(): void
    {
        $this->postJson(self::URL, ['events' => [$this->event()]])->assertStatus(401);
        $this->postJson(self::URL, ['key' => 'pk_nope', 'events' => [$this->event()]])->assertStatus(401);
        $this->withToken('sk_nope')->postJson(self::URL, ['events' => [$this->event()]])->assertStatus(401);
        $this->assertSame(0, Event::count());
    }

    public function test_stores_a_valid_browser_batch(): void
    {
        [$project] = Project::issue('Shop');

        $this->postJson(self::URL, [
            'key' => $project->public_key,
            'events' => [
                $this->event(),
                $this->event(['name' => 'add_to_cart', 'properties' => ['item_id' => 'burger']]),
            ],
        ])->assertStatus(202)->assertJson(['accepted' => 2, 'duplicates' => 0, 'rejected' => []]);

        $this->assertSame(2, Event::where('project_id', $project->id)->where('source', 'browser')->count());
        $this->assertSame(['item_id' => 'burger'], Event::where('name', 'add_to_cart')->first()->properties);
    }

    public function test_retries_are_idempotent(): void
    {
        [$project] = Project::issue('Shop');
        $payload = ['key' => $project->public_key, 'events' => [$this->event()]];

        $this->postJson(self::URL, $payload)->assertStatus(202)->assertJson(['accepted' => 1]);
        $this->postJson(self::URL, $payload)->assertStatus(202)->assertJson(['accepted' => 0, 'duplicates' => 1]);

        $this->assertSame(1, Event::count());
    }

    public function test_one_bad_event_does_not_fail_the_batch(): void
    {
        [$project] = Project::issue('Shop');

        $response = $this->postJson(self::URL, [
            'key' => $project->public_key,
            'events' => [$this->event(), $this->event(['name' => '<script>alert(1)</script>'])],
        ])->assertStatus(202)->assertJson(['accepted' => 1]);

        $this->assertSame(1, $response->json('rejected.0.index'));
        $this->assertSame(1, Event::count());
    }

    public function test_all_invalid_events_return_422(): void
    {
        [$project] = Project::issue('Shop');

        $this->postJson(self::URL, [
            'key' => $project->public_key,
            'events' => [['name' => 'x']],
        ])->assertStatus(422)->assertJson(['accepted' => 0]);
    }

    public function test_batch_size_is_limited(): void
    {
        [$project] = Project::issue('Shop');
        config(['analytics.max_batch' => 2]);

        $this->postJson(self::URL, [
            'key' => $project->public_key,
            'events' => [$this->event(), $this->event(), $this->event()],
        ])->assertStatus(422);
    }

    public function test_oversized_properties_are_rejected(): void
    {
        [$project] = Project::issue('Shop');

        $this->postJson(self::URL, [
            'key' => $project->public_key,
            'events' => [$this->event(['properties' => ['blob' => str_repeat('a', 5000)]])],
        ])->assertStatus(422);
    }

    public function test_accepts_text_plain_body_like_sendbeacon(): void
    {
        [$project] = Project::issue('Shop');
        $body = json_encode(['key' => $project->public_key, 'events' => [$this->event()]]);

        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'], $body)
            ->assertStatus(202)->assertJson(['accepted' => 1]);
    }

    public function test_server_events_use_the_secret_key_and_are_marked_server(): void
    {
        [$project, $secret] = Project::issue('Shop');

        $this->withToken($secret)->postJson(self::URL, [
            // a client cannot choose its own source
            'events' => [$this->event(['name' => 'order_created', 'user_id' => 'cust_1', 'source' => 'browser'])],
        ])->assertStatus(202);

        $event = Event::first();
        $this->assertSame('server', $event->source);
        $this->assertSame('cust_1', $event->user_id);
        $this->assertSame($project->id, $event->project_id);
    }

    public function test_public_key_cannot_be_used_as_a_bearer_token(): void
    {
        [$project] = Project::issue('Shop');

        $this->withToken($project->public_key)
            ->postJson(self::URL, ['events' => [$this->event()]])
            ->assertStatus(401);
    }

    public function test_origin_allow_list_is_enforced_for_browser_keys(): void
    {
        [$project] = Project::issue('Shop', ['https://shop.example']);
        $payload = ['key' => $project->public_key, 'events' => [$this->event()]];

        $this->postJson(self::URL, $payload, ['Origin' => 'https://evil.example'])->assertStatus(403);
        $this->postJson(self::URL, $payload)->assertStatus(403);
        $this->postJson(self::URL, $payload, ['Origin' => 'https://shop.example'])->assertStatus(202);
    }

    public function test_client_clock_is_only_trusted_within_a_window(): void
    {
        [$project] = Project::issue('Shop');

        $this->postJson(self::URL, [
            'key' => $project->public_key,
            'events' => [
                $this->event(['timestamp' => now()->subDays(30)->toIso8601String()]),
                $this->event(['timestamp' => now()->addDays(2)->toIso8601String()]),
                $this->event(['timestamp' => now()->subHours(3)->toIso8601String()]),
            ],
        ])->assertStatus(202);

        $times = Event::orderBy('id')->get()->pluck('occurred_at');

        $this->assertLessThan(60, abs(Carbon::now()->diffInSeconds($times[0])));
        $this->assertLessThan(60, abs(Carbon::now()->diffInSeconds($times[1])));
        // Carbon 3 returns signed differences, hence abs()
        $this->assertEqualsWithDelta(3, abs(Carbon::now()->diffInHours($times[2])), 0.1);
    }

    public function test_requests_are_rate_limited_per_project(): void
    {
        [$project] = Project::issue('Shop');
        config(['analytics.rate_limit_per_minute' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson(self::URL, ['key' => $project->public_key, 'events' => [$this->event()]])->assertStatus(202);
        }

        $this->postJson(self::URL, ['key' => $project->public_key, 'events' => [$this->event()]])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_derives_path_from_url_and_normalises_trailing_slash(): void
    {
        [$project] = Project::issue('Shop');
        $event = $this->event(['url' => 'http://localhost/menu/']);
        unset($event['path']);

        $this->postJson(self::URL, ['key' => $project->public_key, 'events' => [$event]])->assertStatus(202);

        $this->assertSame('/menu', Event::first()->path);
    }
}
