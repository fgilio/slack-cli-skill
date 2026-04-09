<?php

namespace App\Commands;

/**
 * Show message history for a channel.
 */
class MessagesHistoryCommand extends BaseSlackCommand
{
    protected $signature = 'messages:history
        {channel : Channel name, #name, or ID}
        {--limit=50 : Maximum messages to return}
        {--sort=newest : Sort order (newest, oldest)}';

    protected $description = 'Show channel message history';

    protected function doExecute(): int
    {
        $channel = $this->argument('channel');
        $limit = (int) $this->option('limit');
        $sort = $this->option('sort');

        $messages = $this->client->getHistory($channel, $limit, $sort);

        if ($this->wantsJson()) {
            return $this->outputJson($messages);
        }

        if ($messages->isEmpty()) {
            $this->line('No messages found.');

            return self::SUCCESS;
        }

        // Pre-load users for batch resolution
        $this->client->getAllUsers();

        foreach ($messages as $message) {
            $this->line($this->formatMessage($message));
            $this->line('');
        }

        return self::SUCCESS;
    }
}
