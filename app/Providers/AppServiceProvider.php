<?php

namespace App\Providers;

use App\Analytics\ServerTracker;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tracker per request/process so events are buffered and flushed together.
        $this->app->singleton(ServerTracker::class);
    }

    public function boot(): void
    {
        //
    }
}
