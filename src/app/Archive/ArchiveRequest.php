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
        // How far back a --since-last run looks for replies that reached
        // older threads after they were archived. 0 turns the pass off.
        public readonly int $lateReplyDays = LateReplies::DEFAULT_LOOKBACK_DAYS,
    ) {}

    public function messagesPath(): string
    {
        return self::messagesPathIn($this->outDir);
    }

    public static function messagesPathIn(string $outDir): string
    {
        return $outDir.'/messages.jsonl';
    }

    public function monthsDir(): string
    {
        return RawMonths::dirIn($this->outDir);
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
