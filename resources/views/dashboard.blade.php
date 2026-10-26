<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pulse Analytics</title>
    <style>
        :root { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7f9; --card:#fff; --blue:#2563eb; --green:#059669; --amber:#d97706; }
        * { box-sizing: border-box; }
        body { margin:0; font:14px/1.5 system-ui, sans-serif; color:var(--ink); background:var(--bg); }
        header { display:flex; flex-wrap:wrap; gap:12px; align-items:center; padding:16px 24px; background:var(--card); border-bottom:1px solid var(--line); }
        header h1 { font-size:18px; margin:0 12px 0 0; }
        label { color:var(--muted); display:flex; gap:6px; align-items:center; }
        input, select, button { font:inherit; padding:6px 10px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        button { cursor:pointer; }
        button.active { background:var(--ink); color:#fff; border-color:var(--ink); }
        main { max-width:1100px; margin:24px auto; padding:0 24px; display:grid; gap:16px; }
        .kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; }
        .kpi .label { color:var(--muted); }
        .kpi .value { font-size:28px; font-weight:600; }
        .grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(380px, 1fr)); gap:16px; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); }
        th { color:var(--muted); font-weight:500; }
        td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; }
        h2 { font-size:15px; margin:0 0 8px; }
        .tag { padding:1px 6px; border-radius:999px; font-size:12px; background:#eef2ff; color:#3730a3; }
        .tag.server { background:#ecfdf5; color:#065f46; }
        #error { color:#b91c1c; }
        .empty { color:var(--muted); padding:8px; }
    </style>
</head>
<body>
<header>
    <h1>Pulse Analytics</h1>
    <label>Project <select id="project"></select></label>
    <label>From <input type="date" id="from"></label>
    <label>To <input type="date" id="to"></label>
    <button data-days="0">Today</button>
    <button data-days="6" class="active">7 days</button>
    <button data-days="29">30 days</button>
    <button id="refresh">Refresh</button>
    <span id="error"></span>
</header>

<main>
    <section class="kpis">
        <div class="card kpi"><div class="label">Pageviews</div><div class="value" id="k-pageviews">–</div></div>
        <div class="card kpi"><div class="label">Unique visitors</div><div class="value" id="k-visitors">–</div></div>
        <div class="card kpi"><div class="label">Sessions</div><div class="value" id="k-sessions">–</div></div>
        <div class="card kpi"><div class="label">Events (all)</div><div class="value" id="k-events">–</div></div>
    </section>

    <section class="card">
        <h2>Traffic over time <span class="muted" style="font-weight:400">(visitors are unique per day)</span></h2>
        <div style="height:280px"><canvas id="trend"></canvas></div>
    </section>

    <section class="grid2">
        <div class="card">
            <h2>Popular pages</h2>
            <table>
                <thead><tr><th>Path</th><th class="num">Views</th><th class="num">Visitors</th></tr></thead>
                <tbody id="pages"></tbody>
            </table>
        </div>
        <div class="card">
            <h2>Top events</h2>
            <table>
                <thead><tr><th>Event</th><th>Source</th><th class="num">Count</th><th class="num">Visitors</th></tr></thead>
                <tbody id="events"></tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Latest events</h2>
        <table>
            <thead><tr><th>Time (UTC)</th><th>Event</th><th>Path</th><th>Source</th><th>User</th></tr></thead>
            <tbody id="recent"></tbody>
        </table>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    // Event names and paths come from the outside world, so everything is rendered with
    // textContent (never innerHTML) to rule out stored XSS.
    const $ = (id) => document.getElementById(id);
    const fmt = (n) => Number(n).toLocaleString();
    const isoDay = (offsetDays) => new Date(Date.now() - offsetDays * 86400000).toISOString().slice(0, 10);
    let chart = null;

    function cell(text, className) {
        const td = document.createElement('td');
        td.textContent = text === null || text === undefined ? '–' : String(text);
        if (className) td.className = className;
        return td;
    }

    function tagCell(source) {
        const td = document.createElement('td');
        const span = document.createElement('span');
        span.className = 'tag ' + source;
        span.textContent = source;
        td.appendChild(span);
        return td;
    }

    function fill(id, rows, build) {
        const body = $(id);
        body.replaceChildren();
        if (!rows.length) {
            const tr = document.createElement('tr');
            const td = cell('No data in this range', 'empty');
            td.colSpan = 5;
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        rows.forEach((row) => {
            const tr = document.createElement('tr');
            build(row).forEach((c) => tr.appendChild(c));
            body.appendChild(tr);
        });
    }

    function draw(series) {
        const labels = series.map((d) => d.day.slice(5));
        const ds = (label, key, color) => ({
            label, data: series.map((d) => d[key]), borderColor: color, backgroundColor: color,
            tension: 0.25, pointRadius: series.length > 31 ? 0 : 3,
        });
        const datasets = [
            ds('Pageviews', 'pageviews', '#2563eb'),
            ds('Unique visitors', 'visitors', '#059669'),
            ds('All events', 'events', '#d97706'),
        ];
        if (chart) { chart.destroy(); }
        chart = new Chart($('trend'), {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }

    async function load() {
        $('error').textContent = '';
        const params = new URLSearchParams({ from: $('from').value, to: $('to').value });
        if ($('project').value) params.set('project', $('project').value);

        let res;
        try { res = await fetch('{{ route('dashboard.stats') }}?' + params, { headers: { Accept: 'application/json' } }); }
        catch (e) { $('error').textContent = 'Network error'; return; }

        if (!res.ok) {
            const body = await res.json().catch(() => ({}));
            $('error').textContent = body.error || (body.errors ? Object.values(body.errors)[0][0] : 'Failed to load (' + res.status + ')');
            return;
        }
        const d = await res.json();

        const select = $('project');
        if (select.options.length !== d.projects.length) {
            select.replaceChildren();
            d.projects.forEach((p) => select.add(new Option(p.name, p.id)));
        }
        select.value = d.project.id;

        $('k-pageviews').textContent = fmt(d.totals.pageviews);
        $('k-visitors').textContent = fmt(d.totals.visitors);
        $('k-sessions').textContent = fmt(d.totals.sessions);
        $('k-events').textContent = fmt(d.totals.events);

        draw(d.series);
        fill('pages', d.top_pages, (r) => [cell(r.path), cell(fmt(r.views), 'num'), cell(fmt(r.visitors), 'num')]);
        fill('events', d.top_events, (r) => [cell(r.name), tagCell(r.source), cell(fmt(r.count), 'num'), cell(fmt(r.visitors), 'num')]);
        fill('recent', d.recent, (r) => [cell(r.occurred_at), cell(r.name), cell(r.path), tagCell(r.source), cell(r.user_id)]);
    }

    function preset(days) {
        $('to').value = isoDay(0);
        $('from').value = isoDay(days);
        document.querySelectorAll('[data-days]').forEach((b) => b.classList.toggle('active', Number(b.dataset.days) === days));
        load();
    }

    document.querySelectorAll('[data-days]').forEach((b) => b.addEventListener('click', () => preset(Number(b.dataset.days))));
    $('refresh').addEventListener('click', load);
    $('project').addEventListener('change', load);
    ['from', 'to'].forEach((id) => $(id).addEventListener('change', () => {
        document.querySelectorAll('[data-days]').forEach((b) => b.classList.remove('active'));
        load();
    }));

    preset(6);
</script>
</body>
</html>
