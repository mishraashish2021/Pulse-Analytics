<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class CreateProject extends Command
{
    protected $signature = 'analytics:project {name} {--origin=* : Allowed browser origin, e.g. https://shop.example (repeatable)}';

    protected $description = 'Create an analytics project and print its keys (the secret key is shown only once)';

    public function handle(): int
    {
        [$project, $secret] = Project::issue($this->argument('name'), $this->option('origin'));

        $this->info("Project #{$project->id} \"{$project->name}\" created.");
        $this->line("Public key (browser):  {$project->public_key}");
        $this->line("Secret key (server):   {$secret}");
        $this->warn('Store the secret key now; only its hash is kept.');

        return self::SUCCESS;
    }
}
