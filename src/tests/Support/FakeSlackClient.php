<?php

namespace Tests\Support;

use App\Services\SlackClient;
use Generator;
use Illuminate\Support\Collection;

/**
 * A Slack client backed by fixtures.
 *
 * Pages arrive newest first, the way conversations.history delivers them,
 * so the archiver's ordering pass is exercised for real.
 */
final class FakeSlackClient extends SlackClient
{
    public int $historyCalls = 0;

    /**
     * @param  list<list<array<string, mixed>>>  $pages  Newest page first, newest message first inside each page.
     * @param  array<string, list<array<string, mixed>>>  $threads  Replies keyed by parent ts, parent included.
     * @param  array<string, string>  $users
     */
    public function __construct(
        private readonly array $pages,
        private readonly array $threads = [],
        private readonly array $users = [],
    ) {}

    public function historyPages(string $channelId, ?string $oldest = null, ?string $latest = null, ?string $cursor = null): Generator
    {
        $this->historyCalls++;

        $skip = $cursor === null ? 0 : (int) $cursor;

        for ($index = $skip; $index < count($this->pages); $index++) {
            $messages = array_values(array_filter(
                $this->pages[$index],
                fn (array $message) => $this->withinWindow((string) $message['ts'], $oldest, $latest),
            ));

            $next = $index + 1 < count($this->pages) ? (string) ($index + 1) : null;

            yield ['messages' => $messages, 'next_cursor' => $next];

            if ($next === null) {
                return;
            }
        }
    }

    public function repliesPages(string $channelId, string $threadTs, ?string $cursor = null): Generator
    {
        yield ['messages' => $this->threads[$threadTs] ?? [], 'next_cursor' => null];
    }

    public function getChannelInfo(string $channel): ?Collection
    {
        return collect([
            'id' => $channel,
            'name' => 'eng-leadership',
            'is_private' => true,
            'num_members' => 12,
        ]);
    }

    public function getWorkspaceName(): string
    {
        return 'publica.la';
    }

    public function getAuthenticatedUserTimezone(): ?string
    {
        return 'UTC';
    }

    public function getUserName(string $userId): string
    {
        return $this->users[$userId] ?? $userId;
    }

    public function getUserInfo(string $userId): ?Collection
    {
        return isset($this->users[$userId])
            ? collect(['id' => $userId, 'real_name' => $this->users[$userId]])
            : null;
    }

    public function getChannelName(string $channelId): string
    {
        return 'eng-leadership';
    }

    private function withinWindow(string $ts, ?string $oldest, ?string $latest): bool
    {
        if ($oldest !== null && (float) $ts < (float) $oldest) {
            return false;
        }

        return $latest === null || (float) $ts <= (float) $latest;
    }
}
