<?php

namespace App\Archive;

/**
 * An archive directory found on disk, and what it says about itself.
 */
final class ScannedArchive
{
    public const UNKNOWN_TARGET = 'FIXME';

    public function __construct(
        public readonly string $outDir,
        public readonly ?string $target,
        public readonly ?string $label,
    ) {}

    public function toEntry(): BatchEntry
    {
        return new BatchEntry(
            target: $this->target ?? self::UNKNOWN_TARGET,
            outDir: $this->outDir,
        );
    }

    /**
     * What to tell the user about a directory whose target has to be
     * filled in by hand.
     */
    public function note(): string
    {
        if ($this->label !== null) {
            return "{$this->outDir} archives '{$this->label}', which is not a target you can pass back to Slack. Replace its FIXME with the @username or channel ID";
        }

        return "{$this->outDir} carries no record of the conversation it holds. Replace its FIXME with the target it should refresh";
    }
}
