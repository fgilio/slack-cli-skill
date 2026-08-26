<?php

namespace App\Archive;

/**
 * Resolves Slack IDs to the names used when rendering an archive.
 */
interface NameResolver
{
    public function userName(string $userId): string;

    public function channelName(string $channelId): string;
}
