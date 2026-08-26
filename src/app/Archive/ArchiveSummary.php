<?php

namespace App\Archive;

/**
 * What an archive run produced.
 */
final class ArchiveSummary
{
    public function __construct(
        public readonly string $channelId,
        public readonly string $channelLabel,
        public readonly int $messages,
        public readonly int $threads,
        public readonly int $replies,
        public readonly int $skipped,
        public readonly ?string $firstDay,
        public readonly ?string $lastDay,
        public readonly string $messagesPath,
        public readonly string $markdownPath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel_id' => $this->channelId,
            'channel' => $this->channelLabel,
            'messages' => $this->messages,
            'threads' => $this->threads,
            'replies' => $this->replies,
            'skipped' => $this->skipped,
            'first_day' => $this->firstDay,
            'last_day' => $this->lastDay,
            'messages_path' => $this->messagesPath,
            'markdown_path' => $this->markdownPath,
        ];
    }
}
