<?php

namespace App\Commands;

use RuntimeException;

/**
 * Read a thread from a Slack URL.
 */
class ThreadReadCommand extends BaseSlackCommand
{
    protected $signature = 'thread:read
        {url : Slack message or thread URL}
        {--json : Output as JSON}';

    protected $description = 'Read a thread from Slack URL';

    protected function doExecute(): int
    {
        $url = $this->argument('url');

        $parsed = $this->client->parseSlackUrl($url);

        $channelId = $parsed->get('channel');
        $threadTs = $parsed->get('thread_ts') ?? $parsed->get('ts');

        if (! $channelId) {
            throw new RuntimeException('Unable to parse Slack URL');
        }

        // Get channel info
        $channelInfo = $this->client->getChannelInfo($channelId);
        $channelName = $channelInfo?->get('name') ?? $channelId;

        if ($threadTs) {
            // Fetch thread replies
            $messages = $this->client->getThreadReplies($channelId, $threadTs);
        } else {
            // Just fetch recent messages if no specific thread
            $messages = $this->client->getHistory($channelId, 20, 'oldest');
        }

        if ($this->wantsJson()) {
            return $this->outputJson([
                'channel' => $channelName,
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'messages' => $messages->toArray(),
            ]);
        }

        // Pre-load users
        $this->client->getAllUsers();

        // Determine if this is a thread or single message
        $replyCount = $messages->count() - 1; // First message is the OP
        $isThread = $replyCount > 0;

        $this->line('');
        if ($isThread) {
            $this->line("<fg=cyan>Thread in #{$channelName} ({$replyCount} ".($replyCount === 1 ? 'reply' : 'replies').')</>');
        } else {
            $this->line("<fg=cyan>Message in #{$channelName} (no replies)</>");
        }
        $this->line('');

        $first = true;
        foreach ($messages as $message) {
            if ($first && $isThread) {
                $this->line($this->formatMessage($message).' [OP]');
                $this->line('');
                $this->line('---');
                $this->line('');
                $first = false;

                continue;
            }

            $this->line($this->formatMessage($message));
            $this->line('');
            $first = false;
        }

        return self::SUCCESS;
    }
}
