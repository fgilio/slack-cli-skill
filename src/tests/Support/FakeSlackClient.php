<?php

namespace Tests\Support;

use App\Services\SlackClient;
use Generator;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A Slack client backed by fixtures.
 *
 * Pages arrive newest first, the way conversations.history delivers them,
 * so the archiver's ordering pass is exercised for real.
 */
final class FakeSlackClient extends SlackClient
{
    public int $historyCalls = 0;

    /** @var list<?string> The cursor each history walk was asked to start from. */
    public array $cursorsRequested = [];

    /**
     * @param  list<list<array<string, mixed>>>  $pages  Newest page first, newest message first inside each page.
     * @param  array<string, list<array<string, mixed>>>  $threads  Replies keyed by parent ts, parent included.
     * @param  array<string, string>  $users
     * @param  int|null  $crashAfterPages  Die once this many pages have been served, standing in for a killed process.
     */
    public function __construct(
        private readonly array $pages,
        private readonly array $threads = [],
        private readonly array $users = [],
        private readonly ?int $crashAfterPages = null,
    ) {}

    public function historyPages(string $channelId, ?string $oldest = null, ?string $latest = null, ?string $cursor = null): Generator
    {
        $this->historyCalls++;
        $this->cursorsRequested[] = $cursor;

        $skip = $cursor === null ? 0 : (int) $cursor;
        $served = 0;

        for ($index = $skip; $index < count($this->pages); $index++) {
            throw_if(
                $this->crashAfterPages !== null && $served >= $this->crashAfterPages,
                RuntimeException::class,
                'Connection reset while fetching history',
            );

            $messages = array_values(array_filter(
                $this->pages[$index],
                fn (array $message) => $this->withinWindow((string) $message['ts'], $oldest, $latest),
            ));

            $next = $index + 1 < count($this->pages) ? (string) ($index + 1) : null;

            yield ['messages' => $messages, 'next_cursor' => $next];

            $served++;

            if ($next === null) {
                return;
            }
        }
    }

    public function repliesPages(string $channelId, string $threadTs, ?string $cursor = null): Generator
    {
        yield ['messages' => $this->threads[$threadTs] ?? [], 'next_cursor' => null];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function validateAuth(): Collection
    {
        return collect(['ok' => true, 'user' => 'fgilio', 'team' => 'publica.la']);
    }

    public function resolveConversationId(string $identifier): string
    {
        throw_if($identifier === 'missing', RuntimeException::class, 'Channel not found. Check the name or ID');

        return str_starts_with($identifier, '@')
            ? 'D'.strtoupper(substr($identifier, 1))
            : 'C47JM9E9K';
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
