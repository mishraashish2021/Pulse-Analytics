<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Delivers a batch of server events to the ingestion API.
 *  - network error / 5xx / 429  -> retried with backoff (safe: server dedupes on event_id)
 *  - other 4xx (bad payload/key) -> failed immediately, retrying cannot help
 */
class SendAnalyticsEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @param array<int, array<string, mixed>> $events */
    public function __construct(public array $events)
    {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(): void
    {
        $response = Http::withToken((string) config('analytics.secret_key'))
            ->acceptJson()
            ->timeout(3)
            ->post(config('analytics.endpoint'), ['events' => $this->events]);

        if ($response->clientError() && $response->status() !== 429) {
            $this->fail(new \RuntimeException('Analytics API rejected events with HTTP '.$response->status()));

            return;
        }

        $response->throw(); // 5xx / 429 -> exception -> queue retries with backoff
    }
}
