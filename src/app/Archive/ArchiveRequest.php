<?php

namespace App\Archive;

/**
 * What the user asked an archive run to do.
 */
final class ArchiveRequest
{
    public function __construct(
        public readonly string $channelId,
        public readonly string $outDir,
        public readonly ?string $oldest = null,
        public readonly ?string $latest = null,
        public readonly bool $includeThreads = true,
        public readonly bool $resume = false,
        public readonly bool $sinceLast = false,
    ) {}

    public function messagesPath(): string
    {
        return self::messagesPathIn($this->outDir);
    }

    public static function messagesPathIn(string $outDir): string
    {
        return $outDir.'/messages.jsonl';
    }

    public function markdownPath(): string
    {
        return self::markdownPathIn($this->outDir);
    }

    public static function markdownPathIn(string $outDir): string
    {
        return $outDir.'/raw.md';
    }

    public function checkpointPath(): string
    {
        return self::checkpointPathIn($this->outDir);
    }

    public static function checkpointPathIn(string $outDir): string
    {
        return $outDir.'/.archive-checkpoint.json';
    }

    public function workDir(): string
    {
        return $this->outDir.'/.archive-tmp';
    }
}
