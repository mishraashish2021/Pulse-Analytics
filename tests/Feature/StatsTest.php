<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['analytics.dashboard.user' => 'admin', 'analytics.dashboard.password' => 'secret']);
        [$this->project] = Project::issue('Shop');

        // 2026-10-20: 3 pageviews (2 visitors) + 1 add_to_cart   | 2026-10-22: 1 pageview + 1 server order
        $this->insert('page_view', 'a', '/menu', '2026-10-20 12:00:00');
        $this->insert('page_view', 'b', '/menu', '2026-10-20 13:00:00');
        $this->insert('page_view', 'a', '/', '2026-10-20 14:00:00');
        $this->insert('add_to_cart', 'a', '/menu', '2026-10-20 14:05:00');
        $this->insert('page_view', 'a', '/menu', '2026-10-22 09:00:00');
        $this->insert('order_created', 'a', null, '2026-10-22 09:10:00', 'server');
        // outside the range used below
        $this->insert('page_view', 'z', '/old', '2026-10-01 09:00:00');
    }

    private function insert(string $name, string $anon, ?string $path, string $at, string $source = 'browser'): void
    {
        DB::table('events')->insert([
            'project_id' => $this->project->id,
            'event_id' => (string) Str::uuid(),
            'name' => $name,
            'source' => $source,
            'anonymous_id' => $anon,
            'session_id' => 's-'.$anon,
            'path' => $path,
            'occurred_at' => $at,
            'received_at' => $at,
        ]);
    }

    private function authed(): self
    {
        return $this->withHeaders(['Authorization' => 'Basic '.base64_encode('admin:secret')]);
    }

    public function test_dashboard_requires_credentials(): void
    {
        $this->get('/dashboard')->assertStatus(401);
        $this->get('/dashboard/stats')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('admin:wrong')])->get('/dashboard/stats')->assertStatus(401);
    }

    public function test_dashboard_is_disabled_when_no_password_is_configured(): void
    {
        config(['analytics.dashboard.password' => null]);

        $this->get('/dashboard')->assertStatus(503);
    }

    public function test_totals_for_a_date_range(): void
    {
        $response = $this->authed()->getJson('/dashboard/stats?from=2026-10-20&to=2026-10-22')->assertOk();

        $response->assertJsonPath('totals.events', 6)
            ->assertJsonPath('totals.pageviews', 4)
            ->assertJsonPath('totals.visitors', 2)
            ->assertJsonPath('totals.sessions', 2);
    }

    public function test_date_range_filter_excludes_other_days(): void
    {
        $this->authed()->getJson('/dashboard/stats?from=2026-10-22&to=2026-10-22')
            ->assertOk()
            ->assertJsonPath('totals.events', 2)
            ->assertJsonPath('totals.pageviews', 1)
            ->assertJsonPath('totals.visitors', 1);
    }

    public function test_series_has_one_entry_per_day_with_zero_fill(): void
    {
        $series = $this->authed()->getJson('/dashboard/stats?from=2026-10-20&to=2026-10-22')->json('series');

        $this->assertCount(3, $series);
        $this->assertSame(['2026-10-20', 3, 4, 2], [$series[0]['day'], $series[0]['pageviews'], $series[0]['events'], $series[0]['visitors']]);
        $this->assertSame(['2026-10-21', 0, 0, 0], [$series[1]['day'], $series[1]['pageviews'], $series[1]['events'], $series[1]['visitors']]);
        $this->assertSame(['2026-10-22', 1, 2, 1], [$series[2]['day'], $series[2]['pageviews'], $series[2]['events'], $series[2]['visitors']]);
    }

    public function test_popular_pages_are_ranked_by_views(): void
    {
        $pages = $this->authed()->getJson('/dashboard/stats?from=2026-10-20&to=2026-10-22')->json('top_pages');

        $this->assertSame('/menu', $pages[0]['path']);
        $this->assertSame(3, $pages[0]['views']);
        $this->assertSame(2, $pages[0]['visitors']);
        $this->assertSame('/', $pages[1]['path']);
    }

    public function test_top_events_exclude_page_views_and_show_source(): void
    {
        $events = collect($this->authed()->getJson('/dashboard/stats?from=2026-10-20&to=2026-10-22')->json('top_events'));

        $this->assertNull($events->firstWhere('name', 'page_view'));
        $this->assertSame('server', $events->firstWhere('name', 'order_created')['source']);
        $this->assertSame('browser', $events->firstWhere('name', 'add_to_cart')['source']);
    }

    public function test_other_projects_data_is_not_included(): void
    {
        [$other] = Project::issue('Other');
        DB::table('events')->insert([
            'project_id' => $other->id, 'event_id' => (string) Str::uuid(), 'name' => 'page_view', 'source' => 'browser',
            'anonymous_id' => 'x', 'path' => '/x', 'occurred_at' => '2026-10-20 10:00:00', 'received_at' => '2026-10-20 10:00:00',
        ]);

        $this->authed()->getJson('/dashboard/stats?from=2026-10-20&to=2026-10-22&project='.$this->project->id)
            ->assertJsonPath('totals.events', 6);
    }

    public function test_rejects_an_inverted_range(): void
    {
        $this->authed()->getJson('/dashboard/stats?from=2026-10-22&to=2026-10-20')->assertStatus(422);
    }

    public function test_rejects_ranges_longer_than_a_year(): void
    {
        $this->authed()->getJson('/dashboard/stats?from=2024-01-01&to=2026-10-20')->assertStatus(422);
    }
}
