<?php

namespace App\Archive;

use RuntimeException;

/**
 * Progress marker for an archive run.
 *
 * Saved after every page that reaches disk, so an interrupted run
 * restarts at the page it was about to fetch rather than at zero.
 *
 * The byte marks record what the archive held before the run, so a
 * render that died part way is cut back to them and written again.
 * Month sizes are null for a directory still on the single raw.md,
 * which is also what a checkpoint written before raw/ existed reads as.
 */
final class ArchiveCheckpoint
{
    public const VERSION = 1;

    public const PHASE_HISTORY = 'history';

    public const PHASE_ORDER = 'order';

    public const PHASE_THREADS = 'threads';

    public const PHASE_RENDER = 'render';

    public function __construct(
        public string $path,
        public string $channelId,
        public ?string $oldest,
        public ?string $latest,
        public bool $threads,
        public bool $sinceLast,
        public ?string $sinceTs = null,
        public string $phase = self::PHASE_HISTORY,
        public ?string $cursor = null,
        public int $pageCount = 0,
        public int $threadLine = 0,
        public int $messagesBytes = 0,
        public int $markdownBytes = 0,
        /** @var array<string, int>|null */
        public ?array $monthBytes = null,
    ) {}

    public static function load(string $path): ?self
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException("The checkpoint at {$path} was written by a different version of slack-cli. Delete it and archive again");
        }

        return new self(
            path: $path,
            channelId: (string) $decoded['channel_id'],
            oldest: $decoded['oldest'] ?? null,
            latest: $decoded['latest'] ?? null,
            threads: (bool) $decoded['threads'],
            sinceLast: (bool) $decoded['since_last'],
            sinceTs: $decoded['since_ts'] ?? null,
            phase: (string) $decoded['phase'],
            cursor: $decoded['cursor'] ?? null,
            pageCount: (int) $decoded['page_count'],
            threadLine: (int) $decoded['thread_line'],
            messagesBytes: (int) $decoded['messages_bytes'],
            markdownBytes: (int) $decoded['markdown_bytes'],
            monthBytes: self::monthBytesFrom($decoded['month_bytes'] ?? null),
        );
    }

    /**
     * @return array<string, int>|null
     */
    private static function monthBytesFrom(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return collect($raw)->map(fn (mixed $size): int => (int) $size)->all();
    }

    public function save(): void
    {
        $temp = $this->path.'.part';

        file_put_contents($temp, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($temp, $this->path);
    }

    public function delete(): void
    {
        @unlink($this->path);
    }

    /**
     * Whether a stored checkpoint describes the run the user just asked for.
     */
    public function covers(string $channelId, ?string $oldest, ?string $latest, bool $threads, bool $sinceLast): bool
    {
        return $this->channelId === $channelId
            && $this->oldest === $oldest
            && $this->latest === $latest
            && $this->threads === $threads
            && $this->sinceLast === $sinceLast;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'channel_id' => $this->channelId,
            'oldest' => $this->oldest,
            'latest' => $this->latest,
            'threads' => $this->threads,
            'since_last' => $this->sinceLast,
            'since_ts' => $this->sinceTs,
            'phase' => $this->phase,
            'cursor' => $this->cursor,
            'page_count' => $this->pageCount,
            'thread_line' => $this->threadLine,
            'messages_bytes' => $this->messagesBytes,
            'markdown_bytes' => $this->markdownBytes,
            // An empty map has to stay a JSON object to read back as monthly.
            'month_bytes' => $this->monthBytes === null ? null : (object) $this->monthBytes,
        ];
    }
}
