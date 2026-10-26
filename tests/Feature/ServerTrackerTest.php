<?php

namespace Tests\Feature;

use App\Analytics\ServerTracker;
use App\Jobs\SendAnalyticsEvents;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerTrackerTest extends TestCase
{
    public function test_buffers_events_and_dispatches_one_job_on_flush(): void
    {
        Queue::fake();
        config(['analytics.secret_key' => 'sk_test']);

        $tracker = new ServerTracker;
        $tracker->track('order_created', ['total_cents' => 2198], 'anon-1', 'cust_1');
        $tracker->track('invoice_sent');

        Queue::assertNothingPushed(); // nothing leaves the process until flush
        $tracker->flush();

        Queue::assertPushed(SendAnalyticsEvents::class, 1);
        Queue::assertPushed(SendAnalyticsEvents::class, function (SendAnalyticsEvents $job) {
            return count($job->events) === 2
                && $job->events[0]['name'] === 'order_created'
                && $job->events[0]['anonymous_id'] === 'anon-1'
                && $job->events[0]['user_id'] === 'cust_1'
                && $job->events[0]['properties'] === ['total_cents' => 2198]
                && preg_match('/^[0-9a-f-]{36}$/', $job->events[0]['event_id']) === 1
                && ! array_key_exists('user_id', $job->events[1]);
        });
    }

    public function test_is_a_no_op_when_no_secret_key_is_configured(): void
    {
        Queue::fake();
        config(['analytics.secret_key' => null]);

        $tracker = new ServerTracker;
        $tracker->track('order_created');
        $tracker->flush();

        Queue::assertNothingPushed();
    }

    public function test_a_queue_failure_never_breaks_the_caller(): void
    {
        config(['analytics.secret_key' => 'sk_test', 'queue.default' => 'nonexistent']);

        $tracker = new ServerTracker;
        $tracker->track('order_created');
        $tracker->flush(); // must not throw

        $this->assertTrue(true);
    }

    public function test_job_posts_events_with_the_secret_key(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        config(['analytics.secret_key' => 'sk_test', 'analytics.endpoint' => 'http://analytics.test/api/v1/events']);

        (new SendAnalyticsEvents([['event_id' => 'e1', 'name' => 'x']]))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'http://analytics.test/api/v1/events'
            && $request->hasHeader('Authorization', 'Bearer sk_test')
            && $request['events'][0]['name'] === 'x');
    }

    public function test_job_throws_on_server_errors_so_the_queue_retries(): void
    {
        Http::fake(['*' => Http::response('boom', 503)]);
        config(['analytics.secret_key' => 'sk_test']);

        $this->expectException(RequestException::class);

        (new SendAnalyticsEvents([['event_id' => 'e1', 'name' => 'x']]))->handle();
    }

    public function test_job_throws_on_rate_limiting_so_the_queue_retries(): void
    {
        Http::fake(['*' => Http::response('slow down', 429)]);
        config(['analytics.secret_key' => 'sk_test']);

        $this->expectException(RequestException::class);

        (new SendAnalyticsEvents([['event_id' => 'e1', 'name' => 'x']]))->handle();
    }

    public function test_job_does_not_retry_permanent_client_errors(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid key'], 401)]);
        config(['analytics.secret_key' => 'sk_bad']);

        (new SendAnalyticsEvents([['event_id' => 'e1', 'name' => 'x']]))->handle(); // no exception = no retry

        $this->assertTrue(true);
    }
}
