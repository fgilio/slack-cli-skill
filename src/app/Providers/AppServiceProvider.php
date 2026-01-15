<?php

namespace App\Providers;

use App\Services\SlackClient;
use Fgilio\AgentSkillFoundation\Analytics\Analytics;
use Illuminate\Support\ServiceProvider;
use Phar;

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

        if (Phar::running() || getenv('SLACK_CLI_PRODUCTION')) {
            $this->hideDevCommands();
        }
    }

    private function hideDevCommands(): void
    {
        $devCommands = [
            \App\Commands\BuildCommand::class,
            \NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand::class,
            \LaravelZero\Framework\Commands\BuildCommand::class,
            \LaravelZero\Framework\Commands\InstallCommand::class,
            \LaravelZero\Framework\Commands\RenameCommand::class,
            \LaravelZero\Framework\Commands\MakeCommand::class,
            \LaravelZero\Framework\Commands\TestMakeCommand::class,
        ];

        $hidden = config('commands.hidden', []);
        config(['commands.hidden' => array_merge($hidden, $devCommands)]);
    }
}
