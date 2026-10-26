<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard');
    }

    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project' => ['nullable', 'integer', 'exists:projects,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $project = isset($data['project'])
            ? Project::findOrFail($data['project'])
            : Project::orderBy('id')->firstOrFail();

        // All dates are UTC days. Default range: last 7 days including today.
        $to = Carbon::parse($data['to'] ?? now()->utc()->toDateString(), 'UTC')->endOfDay();
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(6)->toDateString(), 'UTC')->startOfDay();

        if ($from->diffInDays($to) > 366) {
            return response()->json(['error' => 'range too large (max 366 days)'], 422);
        }

        $range = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
        $events = fn () => DB::table('events')
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', $range);

        $totals = $events()->selectRaw(
            "COUNT(*) as events,
             COALESCE(SUM(CASE WHEN name = 'page_view' THEN 1 ELSE 0 END), 0) as pageviews,
             COUNT(DISTINCT anonymous_id) as visitors,
             COUNT(DISTINCT session_id) as sessions"
        )->first();

        $day = $this->dayExpression();
        $byDay = $events()
            ->selectRaw(
                "{$day} as day,
                 COUNT(*) as events,
                 COALESCE(SUM(CASE WHEN name = 'page_view' THEN 1 ELSE 0 END), 0) as pageviews,
                 COUNT(DISTINCT anonymous_id) as visitors"
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // Fill days that had no traffic with zeros so the chart has a continuous x-axis.
        $series = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $row = $byDay->get($key);
            $series[] = [
                'day' => $key,
                'pageviews' => (int) ($row->pageviews ?? 0),
                'events' => (int) ($row->events ?? 0),
                'visitors' => (int) ($row->visitors ?? 0),
            ];
        }

        $topPages = $events()
            ->where('name', 'page_view')
            ->whereNotNull('path')
            ->selectRaw('path, COUNT(*) as views, COUNT(DISTINCT anonymous_id) as visitors')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        $topEvents = $events()
            ->where('name', '!=', 'page_view')
            ->selectRaw('name, source, COUNT(*) as count, COUNT(DISTINCT anonymous_id) as visitors')
            ->groupBy('name', 'source')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $recent = $events()
            ->orderByDesc('id')
            ->limit(15)
            ->get(['name', 'source', 'path', 'user_id', 'anonymous_id', 'occurred_at']);

        return response()->json([
            'project' => ['id' => $project->id, 'name' => $project->name],
            'projects' => Project::orderBy('id')->get(['id', 'name']),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'events' => (int) $totals->events,
                'pageviews' => (int) $totals->pageviews,
                'visitors' => (int) $totals->visitors,
                'sessions' => (int) $totals->sessions,
            ],
            'series' => $series,
            'top_pages' => $topPages->map(fn ($r) => [
                'path' => $r->path, 'views' => (int) $r->views, 'visitors' => (int) $r->visitors,
            ])->values(),
            'top_events' => $topEvents->map(fn ($r) => [
                'name' => $r->name, 'source' => $r->source, 'count' => (int) $r->count, 'visitors' => (int) $r->visitors,
            ])->values(),
            'recent' => $recent,
        ]);
    }

    /** SQL expression that turns occurred_at (stored in UTC) into a YYYY-MM-DD string. */
    private function dayExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', occurred_at)",
            'pgsql' => "to_char(occurred_at, 'YYYY-MM-DD')",
            default => "DATE_FORMAT(occurred_at, '%Y-%m-%d')",
        };
    }
}
