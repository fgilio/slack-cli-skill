<?php

namespace App\Commands;

use Illuminate\Support\Arr;

/**
 * Search messages across channels.
 */
class SearchCommand extends BaseSlackCommand
{
    protected $signature = 'search
        {query : Search query}
        {--in= : Filter by channel name or ID}
        {--from= : Filter by user name or ID}
        {--after= : Messages after date (YYYY-MM-DD)}
        {--before= : Messages before date (YYYY-MM-DD)}
        {--sort=recent : Sort by (recent, relevant)}
        {--limit=20 : Maximum results}
        {--json : Output as JSON}';

    protected $description = 'Search messages';

    protected function doExecute(): int
    {
        $query = $this->argument('query');
        $limit = (int) $this->option('limit');
        $sort = $this->option('sort');

        $filters = array_filter([
            'in' => $this->option('in'),
            'from' => $this->option('from'),
            'after' => $this->option('after'),
            'before' => $this->option('before'),
        ]);

        // Map sort option to Slack API
        $sortParam = $sort === 'relevant' ? 'score' : 'timestamp';

        $results = $this->client->searchMessages($query, $filters, $limit, $sortParam);

        if ($this->wantsJson()) {
            return $this->outputJson($results);
        }

        if ($results->isEmpty()) {
            $this->line('No messages found.');

            return self::SUCCESS;
        }

        // Pre-load users
        $this->client->getAllUsers();

        foreach ($results as $result) {
            $this->outputSearchResult($result);
            $this->line('');
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $result */
    private function outputSearchResult(array $result): void
    {
        $channel = Arr::get($result, 'channel.name', 'unknown');
        $userName = Arr::get($result, 'username', '');
        $userId = Arr::get($result, 'user', '');
        $ts = Arr::get($result, 'ts', '');
        $text = Arr::get($result, 'text', '');

        // Resolve user name if not provided
        if (! $userName && $userId) {
            $userName = $this->client->getUserName($userId);
        }

        $formattedText = $this->client->formatMessageText($text);
        $formattedTime = $this->client->formatTimestamp($ts);

        $this->line("#{$channel} | @{$userName} | {$formattedTime}");
        $this->line($formattedText);
    }
}
