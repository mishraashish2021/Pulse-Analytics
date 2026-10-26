<?php

namespace App\Analytics;

use App\Jobs\SendAnalyticsEvents;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Lightweight server-side tracking helper.
 *
 *   app(ServerTracker::class)->track('order_created', ['total_cents' => 2198], $anonymousId, $userId);
 *
 * Events are buffered in memory and handed to the queue once the response has been sent,
 * so tracking adds no latency and can never break the request that triggered it.
 * Delivery (HTTP call, retries with backoff) happens in SendAnalyticsEvents.
 */
class ServerTracker
{
    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    private bool $flushScheduled = false;

    public function track(
        string $name,
        array $properties = [],
        ?string $anonymousId = null,
        ?string $userId = null,
        ?DateTimeInterface $at = null,
    ): void {
        if (! config('analytics.secret_key')) {
            return; // not configured: tracking is a silent no-op
        }

        $this->buffer[] = array_filter([
            'event_id' => (string) Str::uuid(),
            'name' => $name,
            'anonymous_id' => $anonymousId,
            'user_id' => $userId,
            'properties' => $properties ?: null,
            'timestamp' => ($at ? Carbon::instance($at) : now())->toIso8601String(),
        ], fn ($value) => $value !== null);

        if (! $this->flushScheduled) {
            $this->flushScheduled = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    public function flush(): void
    {
        $events = $this->buffer;
        $this->buffer = [];
        $this->flushScheduled = false;

        foreach (array_chunk($events, (int) config('analytics.max_batch')) as $chunk) {
            try {
                SendAnalyticsEvents::dispatch($chunk);
            } catch (\Throwable $e) {
                report($e); // analytics must never take the app down
            }
        }
    }
}
