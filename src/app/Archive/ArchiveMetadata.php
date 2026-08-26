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

    public function __construct(
        public readonly string $channelId,
        public readonly string $channelLabel,
    ) {}

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

        return new self($channelId, is_string($label) && $label !== '' ? $label : $channelId);
    }

    public function save(string $outDir): void
    {
        $path = self::pathIn($outDir);
        $temp = $path.'.part';

        file_put_contents($temp, json_encode([
            'version' => self::VERSION,
            'channel_id' => $this->channelId,
            'channel' => $this->channelLabel,
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        rename($temp, $path);
    }
}
