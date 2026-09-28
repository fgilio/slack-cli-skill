<?php

namespace App\Archive;

use RuntimeException;

/**
 * Finds the archives already sitting in a directory tree.
 *
 * An archive is any directory holding a messages.jsonl. Runs from
 * this version onward leave an .archive-meta.json naming the channel,
 * and older ones are read back from the title line of raw.md.
 */
final class ArchiveScan
{
    /**
     * @param  list<string>  $roots
     * @return list<ScannedArchive>
     */
    public static function directories(array $roots): array
    {
        $found = [];

        foreach ($roots as $root) {
            // A manifest refuses relative paths, so a root given relative to
            // the working directory is resolved before it names any archive.
            $dir = self::absolutePath(BatchManifest::expandPath($root));

            throw_if(
                ! is_dir($dir),
                RuntimeException::class,
                "Unable to scan {$root}. Give --init the directories your archives live in",
            );

            self::walk($dir, $found);
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * Resolves . and .. by hand rather than with realpath(), so a root
     * reached through a symlink keeps the path the user gave it.
     */
    private static function absolutePath(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            $path = getcwd().'/'.$path;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * @param  array<string, ScannedArchive>  $found
     */
    private static function walk(string $dir, array &$found): void
    {
        if (is_file($dir.'/messages.jsonl')) {
            $found[$dir] = self::describe($dir);
        }

        foreach (scandir($dir) ?: [] as $name) {
            // Skipping dot directories keeps the walk out of .archive-tmp
            // and .git, neither of which holds an archive of its own.
            if (str_starts_with($name, '.')) {
                continue;
            }

            $child = $dir.'/'.$name;

            if (is_dir($child)) {
                self::walk($child, $found);
            }
        }
    }

    private static function describe(string $dir): ScannedArchive
    {
        $metadata = ArchiveMetadata::load($dir);

        if ($metadata !== null) {
            return new ScannedArchive($dir, $metadata->channelId, $metadata->channelLabel);
        }

        $label = self::labelFromMarkdown($dir.'/raw.md');

        // A channel header carries the channel's own name. A DM header
        // carries a person's real name, which is not the @handle a target
        // needs, so those come back for the user to fill in.
        $target = $label !== null && str_starts_with($label, '#') ? $label : null;

        return new ScannedArchive($dir, $target, $label);
    }

    private static function labelFromMarkdown(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fgets($handle);
        fclose($handle);

        if ($header === false) {
            return null;
        }

        return preg_match('/^# (.+?) — Dump completo \(/u', $header, $parts) === 1
            ? $parts[1]
            : null;
    }
}
