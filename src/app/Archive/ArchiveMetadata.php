<?php

namespace App\Archive;

/**
 * What conversation an output directory holds.
 *
 * The checkpoint is deleted the moment a run finishes, so without this
 * file nothing on disk says which channel an archive came from. It is
 * what lets `archive:batch --init` rebuild a manifest from a tree of
 * directories somebody archived months ago.
 */
final class ArchiveMetadata
{
    public const VERSION = 1;

    /**
     * Each thread segment says whether replies were nested for the
     * messages after its ts, up to where the next segment starts.
     *
     * @param  list<array{after: ?string, nested: bool}>  $threadSegments
     */
    public function __construct(
        public readonly string $channelId,
        public readonly string $channelLabel,
        public readonly array $threadSegments = [],
    ) {}

    /**
     * Record one more run. A --since-last run with a different --no-threads
     * setting starts a new segment at the newest message it extended from.
     */
    public function withRun(string $channelId, string $channelLabel, ?string $sinceTs, bool $nested): self
    {
        if ($sinceTs === null) {
            return new self($channelId, $channelLabel, [['after' => null, 'nested' => $nested]]);
        }

        // Files written before the field existed came from runs that used the default.
        $segments = $this->threadSegments ?: [['after' => null, 'nested' => true]];

        if (end($segments)['nested'] !== $nested) {
            $segments[] = ['after' => $sinceTs, 'nested' => $nested];
        }

        return new self($channelId, $channelLabel, $segments);
    }

    /**
     * Whether the run that archived the message at this ts nested its thread replies.
     */
    public function nestsRepliesAt(string $ts): bool
    {
        $nested = true;

        foreach ($this->threadSegments as $segment) {
            if ($segment['after'] !== null && Jsonl::compareTimestamps($ts, $segment['after']) <= 0) {
                break;
            }

            $nested = $segment['nested'];
        }

        return $nested;
    }

    public static function pathIn(string $outDir): string
    {
        return $outDir.'/.archive-meta.json';
    }

    public static function load(string $outDir): ?self
    {
        $path = self::pathIn($outDir);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return null;
        }

        $channelId = $decoded['channel_id'] ?? null;

        if (! is_string($channelId) || $channelId === '') {
            return null;
        }

        $label = $decoded['channel'] ?? null;

        return new self(
            $channelId,
            is_string($label) && $label !== '' ? $label : $channelId,
            self::threadSegmentsFrom($decoded['threads'] ?? null),
        );
    }

    /**
     * @return list<array{after: ?string, nested: bool}>
     */
    private static function threadSegmentsFrom(mixed $raw): array
    {
        return collect(is_array($raw) ? $raw : [])
            ->filter(fn (mixed $segment): bool => is_array($segment)
                && is_bool($segment['nested'] ?? null)
                && (($segment['after'] ?? null) === null || is_string($segment['after'])))
            ->map(fn (array $segment): array => ['after' => $segment['after'] ?? null, 'nested' => $segment['nested']])
            ->values()
            ->all();
    }

    public function save(string $outDir): void
    {
        $path = self::pathIn($outDir);
        $temp = $path.'.part';

        file_put_contents($temp, json_encode([
            'version' => self::VERSION,
            'channel_id' => $this->channelId,
            'channel' => $this->channelLabel,
            'threads' => $this->threadSegments,
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        rename($temp, $path);
    }
}
