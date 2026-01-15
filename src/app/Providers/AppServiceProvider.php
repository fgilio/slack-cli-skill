<?php

namespace App\Providers;

use App\Services\SlackClient;
use Fgilio\AgentSkillFoundation\Analytics\Analytics;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }

    public function register(): void
    {
        // Bind Analytics with skill name for tracking
        $this->app->bind(Analytics::class, fn () => new Analytics(config('app.name')));

        // Bind SlackClient as singleton
        $this->app->singleton(SlackClient::class);
    }
}
