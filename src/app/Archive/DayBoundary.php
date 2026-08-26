<?php

namespace App\Archive;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Turns a YYYY-MM-DD day into the Slack timestamp that bounds it.
 *
 * Slack timestamps are seconds with a microsecond suffix. A day
 * boundary is taken in the archiving user's own timezone, so
 * --after=2026-08-01 means the day they lived, not the day
 * UTC lived.
 */
final class DayBoundary
{
    public static function start(mixed $day, DateTimeZone $timezone): ?string
    {
        return self::at($day, $timezone, '00:00:00', '.000000');
    }

    public static function end(mixed $day, DateTimeZone $timezone): ?string
    {
        return self::at($day, $timezone, '23:59:59', '.999999');
    }

    public static function isValidDay(string $day): bool
    {
        $moment = DateTimeImmutable::createFromFormat('Y-m-d', $day, new DateTimeZone('UTC'));

        return $moment !== false && $moment->format('Y-m-d') === $day;
    }

    private static function at(mixed $day, DateTimeZone $timezone, string $time, string $fraction): ?string
    {
        if (! is_string($day) || $day === '') {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$day} {$time}", $timezone);

        throw_if(
            $moment === false || $moment->format('Y-m-d') !== $day,
            RuntimeException::class,
            "Unable to read the date '{$day}'. Use the YYYY-MM-DD format",
        );

        return $moment->format('U').$fraction;
    }
}
