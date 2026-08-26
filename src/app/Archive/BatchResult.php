<?php

namespace App\Archive;

/**
 * What one manifest entry produced, whether or not it worked.
 */
final class BatchResult
{
    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_UP_TO_DATE = 'up to date';

    public const STATUS_FAILED = 'failed';

    private function __construct(
        public readonly BatchEntry $entry,
        public readonly ?ArchiveSummary $summary,
        public readonly ?string $error,
    ) {}

    public static function archived(BatchEntry $entry, ArchiveSummary $summary): self
    {
        return new self($entry, $summary, null);
    }

    public static function failed(BatchEntry $entry, string $error): self
    {
        return new self($entry, null, $error);
    }

    public function succeeded(): bool
    {
        return $this->error === null;
    }

    public function status(): string
    {
        $summary = $this->summary;

        if ($summary === null) {
            return self::STATUS_FAILED;
        }

        return $summary->messages === 0 && $summary->replies === 0
            ? self::STATUS_UP_TO_DATE
            : self::STATUS_ARCHIVED;
    }

    /**
     * The tail of the live progress line.
     */
    public function outcome(): string
    {
        $summary = $this->summary;

        if ($summary === null) {
            return "FAILED: {$this->error}";
        }

        if ($this->status() === self::STATUS_UP_TO_DATE) {
            return self::STATUS_UP_TO_DATE;
        }

        // Spelled out rather than pluralized: the binary drops
        // doctrine/inflector, so Str::plural is not there to call.
        return sprintf(
            '%d %s, %d %s appended',
            $summary->messages,
            $summary->messages === 1 ? 'message' : 'messages',
            $summary->replies,
            $summary->replies === 1 ? 'reply' : 'replies',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $summary = $this->summary;

        return [
            'target' => $this->entry->target,
            'out' => $this->entry->outDir,
            'status' => $this->status(),
            'channel' => $summary?->channelLabel,
            'channel_id' => $summary?->channelId,
            'messages' => $summary->messages ?? 0,
            'replies' => $summary->replies ?? 0,
            'threads' => $summary->threads ?? 0,
            'first_day' => $summary?->firstDay,
            'last_day' => $summary?->lastDay,
            'messages_path' => $summary?->messagesPath,
            'markdown_path' => $summary?->markdownPath,
            'error' => $this->error,
        ];
    }
}
