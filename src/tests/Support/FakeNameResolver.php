<?php

namespace Tests\Support;

use App\Archive\NameResolver;

final class FakeNameResolver implements NameResolver
{
    /**
     * @param  array<string, string>  $users
     * @param  array<string, string>  $channels
     */
    public function __construct(
        private readonly array $users = [],
        private readonly array $channels = [],
    ) {}

    public function userName(string $userId): string
    {
        return $this->users[$userId] ?? $userId;
    }

    public function channelName(string $channelId): string
    {
        return $this->channels[$channelId] ?? $channelId;
    }
}
