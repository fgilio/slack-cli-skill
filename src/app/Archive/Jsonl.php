<?php

namespace App\Archive;

use Generator;
use RuntimeException;

/**
 * Line-at-a-time helpers for the archive's JSONL files.
 *
 * Every operation here reads and writes one record at a time. Nothing
 * in an archive run ever holds the whole channel in memory, which is
 * what lets a 200k-message export run in a default PHP heap.
 */
final class Jsonl
{
    /**
     * @return Generator<int, array<string, mixed>>
     */
    public static function read(string $path): Generator
    {
        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read the archive file at {$path}");
        }

        try {
            $index = 0;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (! is_array($decoded)) {
                    throw new RuntimeException("Line {$index} of {$path} is not valid JSON. Delete the file and archive again");
                }

                yield $index => $decoded;
                $index++;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Order two Slack timestamps.
     *
     * Comparing them as floats loses the microsecond suffix that makes two
     * messages in the same second distinct, so the seconds and the fraction
     * are compared apart.
     */
    public static function compareTimestamps(string $a, string $b): int
    {
        return self::splitTimestamp($a) <=> self::splitTimestamp($b);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function splitTimestamp(string $ts): array
    {
        $parts = explode('.', $ts, 2);

        return [(int) $parts[0], str_pad($parts[1] ?? '', 6, '0')];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public static function sortByTimestamp(array $messages): array
    {
        usort($messages, fn (array $a, array $b) => self::compareTimestamps(
            (string) ($a['ts'] ?? ''),
            (string) ($b['ts'] ?? ''),
        ));

        return $messages;
    }

    /**
     * The first timestamp in a file, read without walking past it.
     */
    public static function firstTimestamp(string $path): ?string
    {
        foreach (self::read($path) as $message) {
            $ts = $message['ts'] ?? null;

            return is_string($ts) ? $ts : null;
        }

        return null;
    }

    /**
     * Concatenate files in the order given.
     *
     * @param  list<string>  $pagePaths
     */
    public static function concat(array $pagePaths, string $destination): int
    {
        $out = fopen($destination, 'wb');

        if ($out === false) {
            throw new RuntimeException("Unable to write the ordered archive file at {$destination}");
        }

        $written = 0;

        try {
            foreach ($pagePaths as $path) {
                if (! is_file($path)) {
                    continue;
                }

                $in = fopen($path, 'rb');

                if ($in === false) {
                    throw new RuntimeException("Unable to read the archive page at {$path}");
                }

                while (($line = fgets($in)) !== false) {
                    if (trim($line) === '') {
                        continue;
                    }

                    fwrite($out, rtrim($line, "\r\n")."\n");
                    $written++;
                }

                fclose($in);
            }
        } finally {
            fclose($out);
        }

        return $written;
    }

    /**
     * Write records to a temporary file and move it into place, so a
     * crash mid-write never leaves a half page that resume would trust.
     *
     * @param  iterable<array<string, mixed>>  $records
     */
    public static function writeAtomic(string $path, iterable $records): void
    {
        $temp = $path.'.part';
        $handle = fopen($temp, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to write the archive file at {$temp}");
        }

        foreach ($records as $record) {
            fwrite($handle, self::encode($record)."\n");
        }

        fclose($handle);
        rename($temp, $path);
    }

    /**
     * The newest channel message already archived, ignoring thread replies.
     *
     * Replies carry a later timestamp than the message they hang off, so
     * resuming from a reply would skip the channel messages posted between
     * a thread's start and its last answer.
     */
    public static function latestChannelTs(string $path): ?string
    {
        $latest = null;

        foreach (self::read($path) as $message) {
            $ts = $message['ts'] ?? null;

            if (! is_string($ts)) {
                continue;
            }

            $threadTs = $message['thread_ts'] ?? null;

            if (is_string($threadTs) && $threadTs !== $ts) {
                continue;
            }

            if ($latest === null || (float) $ts > (float) $latest) {
                $latest = $ts;
            }
        }

        return $latest;
    }

    /**
     * @return array{0: ?string, 1: ?string} The oldest and newest ts in the file.
     */
    public static function boundingTimestamps(string $path): array
    {
        $first = null;
        $last = null;

        foreach (self::read($path) as $message) {
            $ts = $message['ts'] ?? null;

            if (! is_string($ts)) {
                continue;
            }

            $first ??= $ts;
            $last = $ts;
        }

        return [$first, $last];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function encode(array $record): string
    {
        return json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
