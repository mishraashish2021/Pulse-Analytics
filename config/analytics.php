<?php

return [
    // Where server-side events are sent (the ingestion API). In this demo it is the same app.
    'endpoint' => env('ANALYTICS_ENDPOINT', rtrim(env('APP_URL', 'http://127.0.0.1:8000'), '/').'/api/v1/events'),

    // Keys used by the demo app. Public key = browser (write-only). Secret key = server.
    'public_key' => env('ANALYTICS_PUBLIC_KEY'),
    'secret_key' => env('ANALYTICS_SECRET_KEY'),

    // Ingestion limits
    'max_batch' => 50,                 // events per request
    'max_payload_bytes' => 256 * 1024, // request body size
    'rate_limit_per_minute' => 600,    // requests per project+IP per minute

    // Dashboard (HTTP basic auth)
    'dashboard' => [
        'user' => env('ANALYTICS_DASHBOARD_USER', 'admin'),
        'password' => env('ANALYTICS_DASHBOARD_PASSWORD'),
    ],
];
