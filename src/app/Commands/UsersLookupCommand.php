<?php

namespace App\Commands;

/**
 * Search for users by name.
 */
class UsersLookupCommand extends BaseSlackCommand
{
    protected $signature = 'users:lookup
        {query : Search query (name, display name, or partial match)}';

    protected $description = 'Search for users';

    protected function doExecute(): int
    {
        $query = $this->argument('query');

        $users = $this->client->searchUsers($query);

        if ($users->isEmpty()) {
            // Refresh cache and try again
            fwrite(STDERR, "User not found in cache, refreshing...\n");
            $this->client->refreshUserCache();
            $users = $this->client->searchUsers($query);
        }

        if ($this->wantsJson()) {
            return $this->outputJson($users);
        }

        if ($users->isEmpty()) {
            $this->line('No users found.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $this->line($this->formatUserLine($user));
        }

        return self::SUCCESS;
    }
}
