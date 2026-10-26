<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class IngestController extends Controller
{
    private const MAX_PROPERTY_KEYS = 25;

    private const MAX_PROPERTY_BYTES = 4096;

    public function store(Request $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');
        $source = $request->attributes->get('source');
        $events = $request->attributes->get('payload')['events'] ?? null;

        if (! is_array($events) || $events === [] || ! array_is_list($events)) {
            return response()->json(['error' => '"events" must be a non-empty array'], 422);
        }

        $max = (int) config('analytics.max_batch');

        if (count($events) > $max) {
            return response()->json(['error' => "batch too large (max {$max} events)"], 422);
        }

        $now = now()->utc();
        $rows = [];
        $rejected = [];

        foreach ($events as $index => $event) {
            if (! is_array($event)) {
                $rejected[] = ['index' => $index, 'errors' => ['event' => ['must be an object']]];

                continue;
            }

            $validator = Validator::make($event, $this->rules());

            if ($validator->fails()) {
                $rejected[] = ['index' => $index, 'errors' => $validator->errors()->toArray()];

                continue;
            }

            $properties = $event['properties'] ?? null;

            if ($properties !== null && (
                count($properties) > self::MAX_PROPERTY_KEYS
                || strlen((string) json_encode($properties)) > self::MAX_PROPERTY_BYTES
            )) {
                $rejected[] = ['index' => $index, 'errors' => ['properties' => ['too large']]];

                continue;
            }

            $rows[] = [
                'project_id' => $project->id,
                'event_id' => $event['event_id'],
                'name' => $event['name'],
                // Source comes from the key that authenticated the request, never from the payload.
                'source' => $source,
                'anonymous_id' => $event['anonymous_id'] ?? null,
                'user_id' => $event['user_id'] ?? null,
                'session_id' => $event['session_id'] ?? null,
                'url' => $event['url'] ?? null,
                'path' => $this->path($event),
                'referrer' => $event['referrer'] ?? null,
                'properties' => $properties ? json_encode($properties) : null,
                'occurred_at' => $this->occurredAt($event['timestamp'] ?? null, $now)->format('Y-m-d H:i:s'),
                'received_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        $inserted = 0;

        if ($rows !== []) {
            // INSERT OR IGNORE against UNIQUE(project_id, event_id): retries and duplicate
            // deliveries are harmless, and one bad row never fails the whole batch.
            $inserted = DB::table('events')->insertOrIgnore($rows);
        }

        return response()->json([
            'accepted' => $inserted,
            'duplicates' => count($rows) - $inserted,
            'rejected' => $rejected,
        ], $rows === [] ? 422 : 202);
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:\- ]+$/'],
            'anonymous_id' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'string', 'max:128'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:2048'],
            'path' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'properties' => ['nullable', 'array'],
            'timestamp' => ['nullable', 'date'],
        ];
    }

    private function path(array $event): ?string
    {
        $path = $event['path'] ?? (isset($event['url']) ? parse_url($event['url'], PHP_URL_PATH) : null);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = Str::limit($path, 255, '');

        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }

    /**
     * Trust the client clock only within a sane window. Anything older than 7 days or more
     * than 5 minutes in the future (wrong device clock, tampering) is recorded as "now".
     */
    private function occurredAt(?string $timestamp, Carbon $now): Carbon
    {
        if (! $timestamp) {
            return $now->copy();
        }

        try {
            $time = Carbon::parse($timestamp)->utc();
        } catch (\Throwable) {
            return $now->copy();
        }

        if ($time->lt($now->copy()->subDays(7)) || $time->gt($now->copy()->addMinutes(5))) {
            return $now->copy();
        }

        return $time;
    }
}
