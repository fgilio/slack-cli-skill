<?php

namespace App\Providers;

use App\Services\SlackClient;
use Fgilio\AgentSkillFoundation\Console\Concerns\HidesDevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    use HidesDevCommands;

    public function boot(): void
    {
        //
    }

    public function register(): void
    {
        // Bind SlackClient as singleton
        $this->app->singleton(SlackClient::class);

        $this->hideDevCommands();
    }
}
