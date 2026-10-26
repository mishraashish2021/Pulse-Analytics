<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates ingestion requests and decides the event "source":
 *  - "Authorization: Bearer <secret key>"  -> source "server"
 *  - public key in the body / X-Pulse-Key   -> source "browser" (origin allow-list applies)
 *
 * The body is read as raw text and JSON-decoded here, so the browser SDK can send
 * "text/plain" (no CORS preflight) and navigator.sendBeacon works on page unload.
 */
class AuthenticateIngest
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        $badKeyBucket = 'ingest-bad-key:'.$ip;

        if (RateLimiter::tooManyAttempts($badKeyBucket, 30)) {
            return $this->tooManyRequests($badKeyBucket);
        }

        $raw = $request->getContent();

        if (strlen($raw) > (int) config('analytics.max_payload_bytes')) {
            return response()->json(['error' => 'payload too large'], 413);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['error' => 'body must be a JSON object'], 400);
        }

        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            $project = Project::findBySecret($bearer);
            $source = 'server';
        } else {
            $key = $payload['key'] ?? $request->header('X-Pulse-Key');
            $project = is_string($key) ? Project::where('public_key', $key)->first() : null;
            $source = 'browser';
        }

        if (! $project) {
            RateLimiter::hit($badKeyBucket, 60);

            return response()->json(['error' => 'invalid key'], 401);
        }

        if ($source === 'browser') {
            $allowed = $project->allowed_origins ?? [];

            if ($allowed !== [] && ! in_array($request->header('Origin'), $allowed, true)) {
                return response()->json(['error' => 'origin not allowed'], 403);
            }
        }

        $bucket = 'ingest:'.$project->id.':'.$ip;

        if (RateLimiter::tooManyAttempts($bucket, (int) config('analytics.rate_limit_per_minute'))) {
            return $this->tooManyRequests($bucket);
        }

        RateLimiter::hit($bucket, 60);

        $request->attributes->add([
            'project' => $project,
            'source' => $source,
            'payload' => $payload,
        ]);

        return $next($request);
    }

    private function tooManyRequests(string $bucket): Response
    {
        return response()
            ->json(['error' => 'rate limited'], 429)
            ->header('Retry-After', (string) RateLimiter::availableIn($bucket));
    }
}
