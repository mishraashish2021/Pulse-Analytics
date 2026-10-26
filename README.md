# Pulse Analytics — SDK, ingestion API and dashboard (Laravel 11)

A small analytics system: a browser SDK and a server tracker send events to an ingestion API, events are stored in a relational database, and a dashboard shows traffic, unique visitors, popular pages and event trends with date-range filtering. A restaurant demo app exercises the whole flow.

```
 Browser (pulse.js)                         Laravel app
 ┌────────────────────┐   POST /api/v1/events    ┌───────────────────────────────┐
 │ page_view, custom  │  (public key in body,    │ AuthenticateIngest middleware │
 │ events, identify   │   text/plain, batched)   │  - key -> project + source    │
 │ queue in           │ ───────────────────────► │  - origin allow-list          │
 │ localStorage,      │                          │  - rate limit, size limit     │
 │ retry + backoff,   │                          ├───────────────────────────────┤
 │ sendBeacon on exit │                          │ IngestController              │
 └────────────────────┘                          │  - per-event validation       │
                                                 │  - insertOrIgnore (dedupe)  │──► events table
 Server (ServerTracker)  POST (Bearer secret key)│                               │
 ┌────────────────────┐ ───────────────────────► └───────────────────────────────┘
 │ buffer -> queue    │   via SendAnalyticsEvents                 │
 │ job, retry/backoff │   (retries on 5xx/429/network)            ▼
 └────────────────────┘                          DashboardController (SQL aggregates)
                                                 ├─► React app (frontend/, :5173) via proxy
                                                 └─► /dashboard Blade fallback  (basic auth)
```

## Two apps, two ports

| App | URL | What it is |
|---|---|---|
| Laravel (API + restaurant demo) | http://127.0.0.1:8000 | `/` home, `/menu`, `/about`, `/orders/{id}`, `POST /api/v1/events`, fallback Blade dashboard at `/dashboard` |
| React dashboard | http://localhost:5173 | Vite dev server in `frontend/`; proxies `/dashboard/stats` to Laravel |

Use the demo on :8000, then watch the results on the React dashboard on :5173.

## Setup

Requirements: PHP 8.2+ (with `pdo_mysql`), Composer, MySQL 8 (SQLite also works), Node 18+ for the React dashboard.

### 1. Install Laravel 11 and add this code

```bash
composer create-project laravel/laravel:^11.0 pulse-analytics
cd pulse-analytics
# Copy this repository's files on top (merge folders, do not replace them):
#   app/ bootstrap/ config/ database/ public/ resources/ routes/ tests/ frontend/
# From a terminal:  cp -R /path/to/this/repo/. .
```

### 2. Create the database

```sql
CREATE DATABASE pulse_analytics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Configure `.env`

```env
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pulse_analytics
DB_USERNAME=root
DB_PASSWORD=your_password
QUEUE_CONNECTION=database

ANALYTICS_PUBLIC_KEY=pk_demo_local_0123456789abcdef
ANALYTICS_SECRET_KEY=sk_demo_local_change_me_0123456789abcdef
ANALYTICS_DASHBOARD_USER=admin
ANALYTICS_DASHBOARD_PASSWORD=password
```

### 4. Migrate, seed and run

```bash
php artisan config:clear
php artisan migrate --seed          # tables + the "Restaurant Demo" project (keys from .env)
php artisan analytics:fake --days=14   # optional: fake history for the charts

php artisan serve                   # terminal 1 -> http://127.0.0.1:8000
php artisan queue:work              # terminal 2 -> delivers server events (retry/backoff)
```

### 5. Run the React dashboard

```bash
cd frontend
npm install
npm run dev                         # terminal 3 -> http://localhost:5173
```

The browser asks for credentials on first load: `admin` and `ANALYTICS_DASHBOARD_PASSWORD`. If Laravel is not on port 8000, start with `LARAVEL_URL=http://127.0.0.1:PORT npm run dev`. The Vite proxy keeps requests same-origin, so no CORS setup is needed.

### 6. Try the flow

1. Open http://127.0.0.1:8000, then go Home, Menu, About, Menu.
2. Add items, enter a name and email, press **Place order**.
3. Open http://localhost:5173 and press **Refresh**. You will see page views and `add_to_cart`, `checkout_started`, `checkout_completed` (source **browser**) and `order_created` (source **server**). Use the date pickers or the Today / 7 days / 30 days buttons to filter.

### 7. Tests

```bash
php artisan test        # uses in-memory SQLite via phpunit.xml; does not touch your MySQL data
```

### Extra projects

`php artisan analytics:project "My Site" --origin=https://mysite.com` prints a public and a secret key (the secret is shown once; only its SHA-256 is stored).

### Troubleshooting

| Symptom | Fix |
|---|---|
| `Could not open input file: artisan` | Run commands from the Laravel project root. |
| Seeder: "Set ANALYTICS_PUBLIC_KEY..." | Add the `ANALYTICS_*` lines to `.env`, confirm `config/analytics.php` exists, run `php artisan config:clear`. |
| `bootstrap/cache must be writable` | A folder was replaced instead of merged. Redo step 1 with `cp -R`. |
| `order_created` never appears | `php artisan queue:work` is not running (check the `jobs` / `failed_jobs` tables). |
| Vite: "Cannot find module 'tailwindcss'" | `frontend/postcss.config.js` is missing (Vite picked up Laravel's). It must exist with `export default { plugins: {} };`. |
| React dashboard shows a connection error | Laravel is not running, or is on another port (`LARAVEL_URL`). |
| PHP "Deprecated: PDO::MYSQL_ATTR_SSL_CA" lines | Harmless, from running Laravel 11 on PHP 8.5. |

## SDK integration

### Browser (drop-in script)

```html
<script src="/js/pulse.js"
        data-key="pk_your_public_key"
        data-endpoint="https://analytics.example.com/api/v1/events"></script>
```

That alone records page views, including SPA route changes (it hooks `history.pushState`/`replaceState`/`popstate`).

```js
pulse.track('add_to_cart', { item_id: 'burger', price_cents: 1099 }); // custom event
pulse.identify('cust_123');       // attach a user id to all future events
pulse.reset();                    // on logout: forget user id, new anonymous id + session
pulse.flush(true);                // send now (uses sendBeacon); call before a hard navigation
pulse.optOut();                   // persistently disable tracking for this browser
```

Manual init (e.g. in a bundler): `pulse.init({ key, endpoint, autoPageviews: true, captureQuery: false, respectDoNotTrack: false, debug: false })`.

### Server (Laravel helper)

```php
use App\Analytics\ServerTracker;

public function store(Request $request, ServerTracker $tracker)
{
    // ...create the order...
    $tracker->track(
        'order_created',
        ['order_id' => $order->id, 'total_cents' => 2198],
        anonymousId: $request->cookie('pa_aid'),   // set by the browser SDK
        userId: $customerId,
    );
}
```

The helper buffers events and queues one delivery job after the response is sent (`app()->terminating`), so tracking adds no request latency and a failure can never break the request.

### Any language (HTTP)

```bash
curl -X POST http://127.0.0.1:8000/api/v1/events \
  -H "Authorization: Bearer $ANALYTICS_SECRET_KEY" -H "Content-Type: application/json" \
  -d '{"events":[{"event_id":"'$(uuidgen)'","name":"invoice_paid","user_id":"cust_1","properties":{"amount":4200}}]}'
```

## API

`POST /api/v1/events`

| Auth | Used by | `source` stored |
|---|---|---|
| `Authorization: Bearer <secret key>` | servers | `server` |
| `"key": "<public key>"` in the body (or `X-Pulse-Key` header) | browsers | `browser` |

Body: `{ "events": [ { event_id, name, anonymous_id?, user_id?, session_id?, url?, path?, referrer?, properties?, timestamp? } ] }` — up to 50 events, 256 KB.

Responses: `202 {accepted, duplicates, rejected:[{index, errors}]}` (partial success), `422` if nothing valid, `401` bad key, `403` origin not allowed, `413` too large, `429` rate limited (`Retry-After`).

`GET /dashboard/stats?from=YYYY-MM-DD&to=YYYY-MM-DD&project=<id>` (basic auth) returns totals, a zero-filled daily series, top pages, top events and the latest events.

## Data model

- `projects`: `public_key` (browser, write-only), `secret_key_hash` (server key, hashed), `allowed_origins`.
- `events`: `project_id`, `event_id` (UUID, unique per project), `name`, `source`, `anonymous_id`, `user_id`, `session_id`, `url`, `path`, `referrer`, `properties` (JSON), `occurred_at`, `received_at` (both UTC).
- Indexes: `UNIQUE(project_id, event_id)`, `(project_id, occurred_at)`, `(project_id, name, occurred_at)`, `(project_id, path, occurred_at)`, `(project_id, anonymous_id)`.
- Page views are ordinary events named `page_view`. One table keeps ingestion and queries simple.

## Identity model

- **anonymous_id**: UUID created by the SDK, kept in `localStorage` and a first-party cookie (1 year). This is what "unique visitors" counts.
- **session_id**: new after 30 minutes of inactivity.
- **user_id**: set by `identify()` or by server events. Anonymous and user ids appear on the same events, so a visitor can be linked after login or checkout.
- The cookie `pa_aid` is readable by the server on purpose, so server events (like `order_created`) attach to the same visitor who browsed the menu. It is excluded from Laravel cookie encryption in `bootstrap/app.php`.
- The demo never sends the customer's email to analytics; it derives a pseudonymous `cust_<hash>` id.

## Key decisions

- **Idempotent ingestion.** The client generates `event_id`; the database enforces uniqueness and the insert ignores conflicts. Network retries, multi-tab queue races and at-least-once beacons are therefore harmless.
- **Partial success.** One malformed event is reported by index and the rest are stored.
- **Source is derived from the key, never from the payload**, so a browser cannot forge `server` events.
- **Two keys.** The public key is safe to embed (write-only, optional origin allow-list, rate-limited); the secret key authorises server events and is stored only as a hash.
- **`text/plain` body for the browser.** It avoids CORS preflights and lets `navigator.sendBeacon` deliver on page exit. The server parses the raw body as JSON.
- **Delivery.** Browser: batch (20 events / 2 s), persist to `localStorage`, exponential backoff with jitter on network/5xx/429, drop permanently-rejected batches (4xx), `sendBeacon` on `visibilitychange`/`pagehide`. Server: queue job with 5 tries and backoff `5s/30s/2m/10m`, no retry on non-retryable 4xx.
- **Clock handling.** `timestamp` is trusted only within [now − 7 days, now + 5 minutes]; otherwise `received_at` time is used.
- **Privacy defaults.** Query strings are stripped from `url`/`referrer` unless `captureQuery` is on; `optOut()` and optional Do-Not-Track support.
- **Dashboard safety.** Event names and paths are untrusted input. React escapes rendered text (the Blade fallback uses `textContent` only), so stored XSS is not possible. Event names are also restricted to `[A-Za-z0-9_.:- ]`.
- **Dashboard auth** fails closed: no configured password means HTTP 503.
- **Aggregation in SQL**, bucketed by UTC day, using indexes on `(project_id, occurred_at)`.

## Tradeoffs

- **Two dashboards over one JSON API.** The primary dashboard is a React app (`frontend/`, Vite + Recharts). A zero-build Blade version at `/dashboard` is kept as a fallback. Both use `GET /dashboard/stats`.
- **Single `events` table with live `COUNT(DISTINCT)` queries** instead of pre-aggregated rollups: simplest correct approach, fast enough for a demo-scale dataset, but not for hundreds of millions of rows.
- **Server events go through the HTTP API** (even in this single app) to show the real integration path and failure handling, at the cost of a loopback request. A same-app deployment could write directly through a shared service.
- **Basic auth** for the dashboard instead of full user accounts. The React app is a Vite dev server proxying to Laravel; a production deployment would serve the built `dist/` from Laravel or a CDN and use real login/sessions.
- **Restaurant demo is server-rendered Blade**, not React: it exists to show SDK integration, and the SDK is framework-agnostic.
- **Database queue** for server events: no extra infrastructure, lower throughput than Redis.

## Known limitations

- Unique visitors = distinct `anonymous_id`; identified users are not merged across devices. Server events without an `anonymous_id` do not count toward visitors.
- A public key is visible in page source: anyone can send junk events. The origin allow-list stops browsers on other sites but not scripts that fake the `Origin` header; per-IP rate limiting limits the impact.
- No bot/crawler filtering and no IP/geo/user-agent enrichment.
- "Daily unique visitors" in the chart do not sum to the range total (a visitor on two days counts twice there, once in the KPI).
- Day buckets are UTC; there is no per-viewer timezone setting.
- The SDK queue is shared across tabs via `localStorage` with no locking, so two tabs can send the same events twice (deduplicated by `event_id` on the server).
- SPA `page_view` titles are read right after the route change and may be stale if the app updates the title later.
- No data retention or deletion API (GDPR erasure would need one), no consent banner integration beyond `optOut()`.
- Scaling path: partition or roll up `events` by day, add a Redis queue/buffer in front of ingestion, move to a column store (ClickHouse) for large volumes.

## Tests

Run with `php artisan test`. 
`php artisan test` covers: authentication (public/secret/invalid keys), origin allow-list, batch/payload limits, partial success, idempotent retries, `text/plain` beacon bodies, clock clamping, rate limiting, dashboard auth, totals, date-range filtering, zero-filled series, top pages/events, project isolation, tracker buffering, job success/retry/no-retry behaviour, and the demo order flow (server-side pricing, attribution cookie, no email leakage).

## Assumptions

- One deployment serves ingestion and the dashboard; multiple projects are supported by key.
- Event volume is modest; correctness and clarity were prioritised over throughput.
- "Unique visitor" means a distinct browser profile, not a person.