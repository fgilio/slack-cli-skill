<?php

namespace App\Archive;

use App\Services\SlackClient;
use Illuminate\Support\Arr;

/**
 * Name resolver backed by the Slack API, with a per-run memo so a
 * long archive never asks for the same ID twice.
 */
final class SlackNameResolver implements NameResolver
{
    /** @var array<string, string> */
    private array $users = [];

    /** @var array<string, string> */
    private array $channels = [];

    public function __construct(private readonly SlackClient $client) {}

    /**
     * An archive is read months later by people outside the conversation,
     * so it leads with the full name rather than the handle the rest of
     * the CLI shows.
     */
    public function userName(string $userId): string
    {
        return $this->users[$userId] ??= $this->fullName($userId);
    }

    private function fullName(string $userId): string
    {
        $user = $this->client->getUserInfo($userId)?->all();

        if ($user === null) {
            return $this->client->getUserName($userId);
        }

        return Arr::get($user, 'profile.real_name_normalized')
            ?: Arr::get($user, 'real_name')
            ?: Arr::get($user, 'profile.display_name')
            ?: Arr::get($user, 'name')
            ?: $userId;
    }

    public function channelName(string $channelId): string
    {
        return $this->channels[$channelId] ??= $this->client->getChannelName($channelId);
    }
}
