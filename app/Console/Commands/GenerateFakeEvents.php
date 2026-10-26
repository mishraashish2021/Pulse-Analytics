<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateFakeEvents extends Command
{
    protected $signature = 'analytics:fake {--days=14 : How many days back to generate} {--project= : Project id (default: first)}';

    protected $description = 'Insert realistic fake traffic so the dashboard has history to show';

    public function handle(): int
    {
        $project = $this->option('project') ? Project::findOrFail($this->option('project')) : Project::orderBy('id')->first();

        if (! $project) {
            $this->error('No project found. Run: php artisan migrate --seed');

            return self::FAILURE;
        }

        $pages = ['/' => 40, '/menu' => 45, '/about' => 10, '/orders' => 5];
        $rows = [];
        $now = now()->utc();

        for ($d = (int) $this->option('days') - 1; $d >= 0; $d--) {
            $day = $now->copy()->subDays($d)->startOfDay();
            $visitors = random_int(15, 60);

            for ($v = 0; $v < $visitors; $v++) {
                $anon = (string) Str::uuid();
                $session = (string) Str::uuid();
                $at = $day->copy()->addSeconds(random_int(0, 86399));
                if ($at->gt($now)) {
                    $at = $now->copy()->subSeconds(random_int(1, 3600));
                }

                $views = random_int(1, 4);
                for ($i = 0; $i < $views; $i++) {
                    $rows[] = $this->row($project, 'page_view', 'browser', $anon, $session, $this->weighted($pages), $at->copy()->addSeconds($i * 20));
                }

                if (random_int(1, 100) <= 35) {
                    $rows[] = $this->row($project, 'add_to_cart', 'browser', $anon, $session, '/menu', $at->copy()->addSeconds(90), ['item_id' => 'burger']);
                    if (random_int(1, 100) <= 40) {
                        $rows[] = $this->row($project, 'order_created', 'server', $anon, $session, null, $at->copy()->addSeconds(150), ['total_cents' => random_int(900, 4000)], 'cust_'.Str::random(8));
                    }
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('events')->insertOrIgnore($chunk);
        }

        $this->info(count($rows).' fake events inserted into "'.$project->name.'".');

        return self::SUCCESS;
    }

    private function row(Project $project, string $name, string $source, string $anon, string $session, ?string $path, $at, array $props = [], ?string $user = null): array
    {
        return [
            'project_id' => $project->id,
            'event_id' => (string) Str::uuid(),
            'name' => $name,
            'source' => $source,
            'anonymous_id' => $anon,
            'user_id' => $user,
            'session_id' => $session,
            'url' => $path ? 'http://127.0.0.1:8000'.$path : null,
            'path' => $path,
            'referrer' => null,
            'properties' => $props ? json_encode($props) : null,
            'occurred_at' => $at->format('Y-m-d H:i:s'),
            'received_at' => $at->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, int> $weights */
    private function weighted(array $weights): string
    {
        $roll = random_int(1, array_sum($weights));
        foreach ($weights as $path => $weight) {
            if (($roll -= $weight) <= 0) {
                return $path;
            }
        }

        return array_key_first($weights);
    }
}
