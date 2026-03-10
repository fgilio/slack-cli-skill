<?php

namespace App\Providers;

use App\Services\SlackClient;
use Fgilio\AgentSkillFoundation\Analytics\Analytics;
use Fgilio\AgentSkillFoundation\Console\BuildCommand;
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
        // Bind Analytics with skill name for tracking
        $this->app->bind(Analytics::class, fn () => new Analytics(config('app.name')));

        // Bind SlackClient as singleton
        $this->app->singleton(SlackClient::class);

        $this->hideDevCommands([
            BuildCommand::class,
            \NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand::class,
            \LaravelZero\Framework\Commands\BuildCommand::class,
            \LaravelZero\Framework\Commands\InstallCommand::class,
            \LaravelZero\Framework\Commands\RenameCommand::class,
            \LaravelZero\Framework\Commands\MakeCommand::class,
            \LaravelZero\Framework\Commands\TestMakeCommand::class,
        ]);
    }
}
