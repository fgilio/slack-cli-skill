<?php

namespace App\Archive;

use RuntimeException;

/**
 * The list of conversations a batch run archives, read from a JSON file.
 *
 * The whole manifest is validated before the first Slack call goes out, so
 * a typo in the last entry surfaces in a second rather than forty minutes
 * into a run.
 */
final class BatchManifest
{
    private const FIELDS = ['target', 'out', 'after', 'before', 'no_threads'];

    /**
     * @param  list<BatchEntry>  $entries
     */
    private function __construct(public readonly array $entries) {}

    public static function fromFile(string $path): self
    {
        $expanded = self::expandPath($path);

        throw_if(
            ! is_file($expanded),
            RuntimeException::class,
            "Unable to read the manifest at {$path}. Give archive:batch the path to a JSON file holding an array of entries",
        );

        return self::fromJson((string) file_get_contents($expanded), $path);
    }

    public static function fromJson(string $json, string $source): self
    {
        $decoded = json_decode($json, true);

        throw_if(
            ! is_array($decoded),
            RuntimeException::class,
            "The manifest at {$source} is not valid JSON: ".json_last_error_msg(),
        );

        throw_if(
            ! array_is_list($decoded),
            RuntimeException::class,
            "The manifest at {$source} must be a JSON array of entries, each with a target and an out",
        );

        throw_if(
            $decoded === [],
            RuntimeException::class,
            "The manifest at {$source} is empty. Add at least one entry with a target and an out",
        );

        $errors = [];
        $entries = [];
        $directories = [];

        foreach ($decoded as $index => $raw) {
            $position = $index + 1;

            if (! is_array($raw) || array_is_list($raw)) {
                $errors[] = "Entry {$position} must be a JSON object with a 'target' and an 'out'";

                continue;
            }

            $entry = self::readEntry($raw, $position, $errors);

            if ($entry === null) {
                continue;
            }

            if (isset($directories[$entry->outDir])) {
                $errors[] = "Entry {$position} ({$entry->target}) writes into {$entry->outDir}, which entry {$directories[$entry->outDir]} already claims";

                continue;
            }

            $directories[$entry->outDir] = $position;
            $entries[] = $entry;
        }

        throw_if(
            $errors !== [],
            RuntimeException::class,
            "The manifest at {$source} has ".count($errors).' problem'.(count($errors) === 1 ? '' : 's').":\n  - ".implode("\n  - ", $errors),
        );

        return new self($entries);
    }

    /**
     * The entries a --only glob selects, or all of them when no glob is given.
     *
     * @return list<BatchEntry>
     */
    public function select(?string $pattern): array
    {
        if ($pattern === null || $pattern === '') {
            return $this->entries;
        }

        $glob = self::glob($pattern);

        $selected = array_values(array_filter(
            $this->entries,
            fn (BatchEntry $entry) => $entry->matches($glob),
        ));

        throw_if(
            $selected === [],
            RuntimeException::class,
            "No manifest entry matches --only={$pattern}. Available entries: ".$this->available(),
        );

        return $selected;
    }

    /**
     * A bare word matches anywhere, so --only=gparra finds the @gparra entry
     * without the caller spelling out the stars.
     */
    private static function glob(string $pattern): string
    {
        return preg_match('/[*?\[]/', $pattern) === 1 ? $pattern : "*{$pattern}*";
    }

    private function available(): string
    {
        return implode(', ', array_map(
            fn (BatchEntry $entry) => "{$entry->target} ({$entry->label()})",
            $this->entries,
        ));
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @param  list<string>  $errors
     */
    private static function readEntry(array $raw, int $position, array &$errors): ?BatchEntry
    {
        $target = $raw['target'] ?? null;
        $out = $raw['out'] ?? null;

        $name = is_string($target) && trim($target) !== ''
            ? "Entry {$position} ({$target})"
            : "Entry {$position}";

        $valid = true;

        foreach (array_keys($raw) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                $errors[] = "{$name} has an unknown field '{$field}'. The fields an entry takes are ".implode(', ', self::FIELDS);
                $valid = false;
            }
        }

        if (! is_string($target) || trim($target) === '') {
            $errors[] = "{$name} needs a 'target': a channel name, a channel ID, or a @username";
            $valid = false;
        }

        if (! is_string($out) || trim($out) === '') {
            $errors[] = "{$name} needs an 'out': the directory to archive into";
            $valid = false;
        }

        $outDir = is_string($out) ? self::expandPath($out) : '';

        if ($outDir !== '' && ! str_starts_with($outDir, '/')) {
            $errors[] = "{$name} has an 'out' of '{$out}', which is a relative path. Give it an absolute path, or one starting with ~";
            $valid = false;
        }

        foreach (['after', 'before'] as $field) {
            $day = $raw[$field] ?? null;

            if ($day === null) {
                continue;
            }

            if (! is_string($day) || ! DayBoundary::isValidDay($day)) {
                $errors[] = "{$name} has an invalid '{$field}'. Use the YYYY-MM-DD format";
                $valid = false;
            }
        }

        $noThreads = $raw['no_threads'] ?? false;

        if (! is_bool($noThreads)) {
            $errors[] = "{$name} has a 'no_threads' that is not true or false";
            $valid = false;
        }

        if (! $valid) {
            return null;
        }

        return new BatchEntry(
            target: trim((string) $target),
            outDir: $outDir,
            after: is_string($raw['after'] ?? null) ? $raw['after'] : null,
            before: is_string($raw['before'] ?? null) ? $raw['before'] : null,
            noThreads: (bool) $noThreads,
        );
    }

    /**
     * Manifests are written by hand, so ~ is spelled the way a shell would
     * expand it rather than the way PHP reads it.
     */
    public static function expandPath(string $path): string
    {
        $path = trim($path);
        $home = getenv('HOME');

        if ($path === '~' && is_string($home)) {
            return $home;
        }

        if (str_starts_with($path, '~/') && is_string($home)) {
            $path = rtrim($home, '/').substr($path, 1);
        }

        return $path === '/' ? $path : rtrim($path, '/');
    }
}
