<?php

namespace App\Archive;

use RuntimeException;

/**
 * The raw/ directory: one reading-copy file per calendar month.
 *
 * A month's file never changes once the next month opens, so tools that
 * index the archive file by file only see the newest month move. A file
 * exists for every month that holds at least one day section.
 */
final class RawMonths
{
    private const FILE = '/^(\d{4}-\d{2})\.md$/';

    public static function dirIn(string $outDir): string
    {
        return $outDir.'/raw';
    }

    public static function legacyPathIn(string $outDir): string
    {
        return $outDir.'/raw.md';
    }

    /**
     * A directory the single-file layout wrote and nothing has moved to
     * raw/ yet. Refreshing and checking it keep to raw.md, rendering it
     * moves it to raw/.
     */
    public static function isLegacy(string $outDir): bool
    {
        return is_file(self::legacyPathIn($outDir)) && ! is_dir(self::dirIn($outDir));
    }

    public static function pathOf(string $dir, string $month): string
    {
        return "{$dir}/{$month}.md";
    }

    /**
     * The months with a file in the directory, oldest first.
     *
     * @return list<string>
     */
    public static function in(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $months = collect(scandir($dir) ?: [])
            ->map(fn (string $name): ?string => preg_match(self::FILE, $name, $parts) === 1 ? $parts[1] : null)
            ->filter()
            ->sort()
            ->values()
            ->all();

        /** @var list<string> $months */
        return $months;
    }

    public static function newestIn(string $dir): ?string
    {
        $months = self::in($dir);

        return $months === [] ? null : end($months);
    }

    /**
     * The size of every month file, the mark a resumed run cuts back to.
     *
     * @return array<string, int>
     */
    public static function sizesIn(string $dir): array
    {
        clearstatcache();

        return collect(self::in($dir))
            ->mapWithKeys(fn (string $month): array => [$month => (int) filesize(self::pathOf($dir, $month))])
            ->all();
    }

    /**
     * Put the directory back the way a run found it: every month it
     * recorded cut back to its size, and every other file the run may
     * have left (a newer month, a half-written .part) removed.
     *
     * @param  array<string, int>  $sizes
     */
    public static function cutBackTo(string $dir, array $sizes): void
    {
        clearstatcache();

        foreach (self::files($dir) as $name) {
            $month = preg_match(self::FILE, $name, $parts) === 1 ? $parts[1] : null;

            if (str_ends_with($name, '.md.part') || ($month !== null && ! array_key_exists($month, $sizes))) {
                @unlink("{$dir}/{$name}");
            }
        }

        foreach ($sizes as $month => $size) {
            $path = self::pathOf($dir, $month);

            throw_unless(
                is_file($path) && filesize($path) >= $size,
                RuntimeException::class,
                "The month file {$path} lost bytes since the interrupted run started. Delete .archive-checkpoint.json beside raw/ and archive again",
            );

            if (filesize($path) > $size) {
                $handle = fopen($path, 'r+b');

                throw_if($handle === false, RuntimeException::class, "Unable to write the archive file at {$path}");

                ftruncate($handle, $size);
                fclose($handle);
            }
        }
    }

    /**
     * Move a full render into place. Each month lands with one rename,
     * and months the render no longer holds are removed.
     */
    public static function replace(string $dir, string $rendered): void
    {
        $months = self::in($rendered);

        if (! is_dir($dir) && $months === []) {
            self::remove($rendered);

            return;
        }

        if (! is_dir($dir)) {
            throw_unless(rename($rendered, $dir), RuntimeException::class, "Unable to move the render at {$rendered} into place at {$dir}");

            return;
        }

        foreach ($months as $month) {
            throw_unless(
                rename(self::pathOf($rendered, $month), self::pathOf($dir, $month)),
                RuntimeException::class,
                "Unable to move the rendered {$month}.md into place in {$dir}",
            );
        }

        foreach (array_diff(self::in($dir), $months) as $stale) {
            @unlink(self::pathOf($dir, $stale));
        }

        self::remove($rendered);
    }

    /**
     * The first place the archive's raw/ parts from a render of it,
     * in month order. A month the archive lacks, or holds without a
     * render, counts as a difference of its own.
     */
    public static function firstDifference(string $dir, string $rendered): ?RenderDifference
    {
        $current = self::in($dir);
        $expected = self::in($rendered);

        $months = array_unique([...$current, ...$expected]);
        sort($months);

        foreach ($months as $month) {
            $file = "raw/{$month}.md";

            if (! in_array($month, $current, true)) {
                return RenderDifference::missing($file);
            }

            if (! in_array($month, $expected, true)) {
                return RenderDifference::unexpected($file);
            }

            $difference = RenderDifference::between($file, self::pathOf($dir, $month), self::pathOf($rendered, $month));

            if ($difference !== null) {
                return $difference;
            }
        }

        return null;
    }

    /**
     * Delete a directory of month files and the directory itself.
     */
    public static function remove(string $dir): void
    {
        foreach (self::files($dir) as $name) {
            @unlink("{$dir}/{$name}");
        }

        @rmdir($dir);
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(
            scandir($dir) ?: [],
            fn (string $name): bool => is_file("{$dir}/{$name}"),
        ));
    }
}
