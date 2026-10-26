<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Ember & Oak') — Restaurant Demo</title>
    <style>
        :root { --ink:#1f2328; --muted:#6b7280; --line:#e5e7eb; --accent:#c2410c; --bg:#fffaf5; }
        * { box-sizing: border-box; }
        body { margin:0; font:16px/1.5 system-ui, sans-serif; color:var(--ink); background:var(--bg); }
        header { display:flex; align-items:center; gap:24px; padding:16px 24px; border-bottom:1px solid var(--line); background:#fff; }
        header strong { font-size:20px; color:var(--accent); }
        header a { color:var(--ink); text-decoration:none; }
        header a:hover { color:var(--accent); }
        header .spacer { flex:1; }
        main { max-width:900px; margin:32px auto; padding:0 24px; }
        button { font:inherit; padding:8px 14px; border-radius:8px; border:1px solid var(--accent); background:var(--accent); color:#fff; cursor:pointer; }
        button.secondary { background:#fff; color:var(--accent); }
        button:disabled { opacity:.5; cursor:not-allowed; }
        input { font:inherit; padding:8px 10px; border:1px solid var(--line); border-radius:8px; width:100%; }
        .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px; }
        .muted { color:var(--muted); }
    </style>
</head>
<body>
<header>
    <strong>Ember &amp; Oak</strong>
    <a href="{{ route('demo.home') }}">Home</a>
    <a href="{{ route('demo.menu') }}">Menu</a>
    <a href="{{ route('demo.about') }}">About</a>
    <span class="spacer"></span>
    <a href="{{ route('dashboard') }}">Analytics dashboard →</a>
</header>
<main>
    @yield('content')
</main>

{{-- Pulse SDK: auto-initialises from these attributes and records the page view. --}}
<script src="{{ asset('js/pulse.js') }}"
        data-key="{{ config('analytics.public_key') }}"
        data-endpoint="{{ url('/api/v1/events') }}"
        data-debug="{{ config('app.debug') ? 'true' : 'false' }}"></script>
@stack('scripts')
</body>
</html>
