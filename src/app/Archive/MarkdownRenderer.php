<?php

namespace App\Archive;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Arr;

/**
 * Renders an archive as the reading copy (raw.md).
 *
 * The renderer is a stream: it emits one chunk per channel message and
 * remembers only the day it last wrote, so a million-message archive
 * costs the same memory as a ten-message one.
 */
final class MarkdownRenderer
{
    private ?string $currentDay;

    public function __construct(
        private readonly SlackMarkup $markup,
        private readonly NameResolver $names,
        private readonly DateTimeZone $timezone,
        ?string $lastRenderedDay = null,
    ) {
        $this->currentDay = $lastRenderedDay;
    }

    public function header(string $channelLabel, string $from, string $to, string $workspace, string $channelMeta): string
    {
        return "# {$channelLabel} — Dump completo ({$from} a {$to})\n\n"
            ."Workspace: {$workspace}. {$channelMeta}\n"
            ."Horarios en {$this->timezone->getName()} .\n";
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $replies
     */
    public function message(array $message, array $replies = []): string
    {
        $day = $this->day((string) Arr::get($message, 'ts', '0'));

        $chunk = '';

        if ($day !== $this->currentDay) {
            $chunk .= "\n## {$day}\n";
            $this->currentDay = $day;
        }

        $chunk .= "\n".$this->block($message, '');

        foreach ($replies as $reply) {
            $chunk .= $this->block($reply, '  > ');
        }

        return $chunk;
    }

    public function day(string $ts): string
    {
        return self::dayOf($ts, $this->timezone);
    }

    public static function dayOf(string $ts, DateTimeZone $timezone): string
    {
        return (new DateTimeImmutable('@'.(int) explode('.', $ts)[0]))
            ->setTimezone($timezone)
            ->format('Y-m-d');
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function block(array $message, string $prefix): string
    {
        $time = $this->moment((string) Arr::get($message, 'ts', '0'))->format('H:i');
        $lines = $this->bodyLines($message);
        $first = array_shift($lines) ?? '';

        $block = $prefix.'**'.$time.' '.$this->displayName($message).':**'
            .($first === '' ? '' : ' '.$first)."\n";

        foreach ($lines as $line) {
            $block .= $prefix.'  '.$line."\n";
        }

        return $block;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private function bodyLines(array $message): array
    {
        if (Arr::get($message, 'subtype') === 'tombstone') {
            return ['[mensaje eliminado]'];
        }

        $text = $this->markup->decode((string) Arr::get($message, 'text', ''));

        /** @var list<string> $lines */
        $lines = array_map(rtrim(...), preg_split('/\r\n|\r|\n/', $text) ?: []);

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return [...$lines, ...$this->attachmentLines($message)];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private function attachmentLines(array $message): array
    {
        $lines = [];

        /** @var array<int, array<string, mixed>> $files */
        $files = Arr::get($message, 'files', []);

        foreach ($files as $file) {
            $name = (string) Arr::get($file, 'name', Arr::get($file, 'title', 'archivo'));
            $type = (string) Arr::get($file, 'mimetype', Arr::get($file, 'filetype', 'desconocido'));
            $size = $this->humanSize((int) Arr::get($file, 'size', 0));

            $lines[] = "[archivo adjunto: {$name} ({$type} - {$size})]";
        }

        return $lines;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').' '.$units[$unit];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function displayName(array $message): string
    {
        $userId = Arr::get($message, 'user');

        if (is_string($userId) && $userId !== '') {
            return $this->names->userName($userId);
        }

        $username = Arr::get($message, 'username') ?? Arr::get($message, 'bot_profile.name');

        if (is_string($username) && $username !== '') {
            return $username;
        }

        $botId = Arr::get($message, 'bot_id');

        return is_string($botId) && $botId !== '' ? $botId : 'desconocido';
    }

    private function moment(string $ts): DateTimeImmutable
    {
        $seconds = (int) explode('.', $ts)[0];

        return (new DateTimeImmutable('@'.$seconds))->setTimezone($this->timezone);
    }
}
