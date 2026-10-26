<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the demo project using the keys from .env so the demo app,
     * the browser SDK and the server tracker all agree.
     */
    public function run(): void
    {
        $public = config('analytics.public_key');
        $secret = config('analytics.secret_key');

        if (! $public || ! $secret) {
            throw new \RuntimeException('Set ANALYTICS_PUBLIC_KEY and ANALYTICS_SECRET_KEY in .env first.');
        }

        Project::updateOrCreate(
            ['public_key' => $public],
            ['name' => 'Restaurant Demo', 'secret_key_hash' => Project::hashSecret($secret), 'allowed_origins' => null],
        );
    }
}
