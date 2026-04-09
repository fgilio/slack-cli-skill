<?php

namespace App\Commands;

/**
 * List channels the user has access to.
 */
class ChannelsListCommand extends BaseSlackCommand
{
    protected $signature = 'channels:list
        {--type=all : Filter by type (public, private, dm, all)}
        {--limit=50 : Maximum channels to return}';

    protected $description = 'List channels';

    protected function doExecute(): int
    {
        $type = $this->option('type');
        $limit = (int) $this->option('limit');

        // Map type option to Slack API types
        $types = match ($type) {
            'public' => 'public_channel',
            'private' => 'private_channel',
            'dm' => 'im,mpim',
            'all', null => 'public_channel,private_channel,mpim,im',
            default => $type,
        };

        $channels = $this->client->listChannels($types, $limit);

        if ($this->wantsJson()) {
            return $this->outputJson($channels);
        }

        if ($channels->isEmpty()) {
            $this->line('No channels found.');

            return self::SUCCESS;
        }

        // Batch load users for DM resolution
        if (str_contains($types, 'im')) {
            $this->client->getAllUsers();
        }

        foreach ($channels as $channel) {
            $this->line($this->formatChannelLine($channel));
        }

        return self::SUCCESS;
    }
}
