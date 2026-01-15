<?php

namespace App\Commands;

use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Show detailed user information.
 */
class UsersInfoCommand extends BaseSlackCommand
{
    protected $signature = 'users:info
        {user : User ID, @name, or display name}
        {--json : Output as JSON}';

    protected $description = 'Show user details';

    protected function doExecute(): int
    {
        $userArg = $this->argument('user');

        // Try to resolve user ID
        $userId = $this->client->resolveUserId($userArg);
        $user = $this->client->getUserInfo($userId);

        if (! $user) {
            throw new RuntimeException('User not found');
        }

        if ($this->wantsJson()) {
            return $this->outputJson($user);
        }

        $this->outputUserInfo($user);

        return self::SUCCESS;
    }

    /** @param \Illuminate\Support\Collection<string, mixed> $user */
    private function outputUserInfo($user): void
    {
        $name = Arr::get($user, 'name', '');
        $id = Arr::get($user, 'id', '');
        $realName = Arr::get($user, 'real_name', Arr::get($user, 'profile.real_name', ''));
        $displayName = Arr::get($user, 'profile.display_name', '');
        $title = Arr::get($user, 'profile.title', '');
        $status = Arr::get($user, 'profile.status_emoji', '');
        $statusText = Arr::get($user, 'profile.status_text', '');
        $tz = Arr::get($user, 'tz', '');
        $tzLabel = Arr::get($user, 'tz_label', '');
        $email = Arr::get($user, 'profile.email', '');
        $phone = Arr::get($user, 'profile.phone', '');

        $display = $displayName ?: $realName ?: $name;

        $this->line('');
        $this->line("<fg=cyan>User:</> {$display} (@{$name})");
        $this->line("<fg=gray>ID:</> {$id}");

        if ($title) {
            $this->line("<fg=gray>Title:</> {$title}");
        }

        if ($status || $statusText) {
            $statusStr = trim("{$status} {$statusText}");
            $this->line("<fg=gray>Status:</> {$statusStr}");
        }

        if ($tz) {
            $tzDisplay = $tzLabel ? "{$tz} ({$tzLabel})" : $tz;
            $this->line("<fg=gray>Timezone:</> {$tzDisplay}");
        }

        if ($email) {
            $this->line("<fg=gray>Email:</> {$email}");
        }

        if ($phone) {
            $this->line("<fg=gray>Phone:</> {$phone}");
        }

        $this->line('');
    }
}
