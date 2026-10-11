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
        return (new ArchiveHeader($channelLabel, $from, $to, $workspace, $channelMeta, $this->timezone->getName()))
            ->toMarkdown();
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

        return [
            ...$this->textLines((string) Arr::get($message, 'text', '')),
            ...$this->fileLines($message),
            ...$this->sharedMessageLines($message),
        ];
    }

    /**
     * @return list<string>
     */
    private function textLines(string $text): array
    {
        /** @var list<string> $lines */
        $lines = array_map(rtrim(...), preg_split('/\r\n|\r|\n/', $this->markup->decode($text)) ?: []);

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private function fileLines(array $message): array
    {
        /** @var array<int, array<string, mixed>> $files */
        $files = Arr::get($message, 'files', []);

        return collect($files)
            ->map(function (array $file): string {
                $name = (string) Arr::get($file, 'name', Arr::get($file, 'title', 'archivo'));
                $type = (string) Arr::get($file, 'mimetype', Arr::get($file, 'filetype', 'desconocido'));
                $size = $this->humanSize((int) Arr::get($file, 'size', 0));

                return "[archivo adjunto: {$name} ({$type} - {$size})]";
            })
            ->values()
            ->all();
    }

    /**
     * A shared or forwarded message arrives as an attachment, and often is
     * the whole message: sharing without a comment leaves `text` empty.
     *
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private function sharedMessageLines(array $message): array
    {
        /** @var array<int, array<string, mixed>> $attachments */
        $attachments = Arr::get($message, 'attachments', []);

        return collect($attachments)
            ->filter(fn (array $attachment): bool => $this->isSharedMessage($attachment))
            ->flatMap(fn (array $shared): array => [
                $this->sharedMessageLabel($shared),
                ...array_map(fn (string $line): string => rtrim('> '.$line), $this->sharedMessageBody($shared)),
            ])
            ->values()
            ->all();
    }

    /**
     * Website previews carry neither flag, so they stay out of the archive.
     *
     * @param  array<string, mixed>  $attachment
     */
    private function isSharedMessage(array $attachment): bool
    {
        return (bool) Arr::get($attachment, 'is_share', false)
            || (bool) Arr::get($attachment, 'is_msg_unfurl', false);
    }

    /**
     * @param  array<string, mixed>  $shared
     */
    private function sharedMessageLabel(array $shared): string
    {
        $ts = Arr::get($shared, 'ts');

        $when = is_string($ts) && $ts !== ''
            ? ', '.$this->moment($ts)->format('Y-m-d H:i')
            : '';

        return "[mensaje compartido de {$this->sharedMessageAuthor($shared)}{$when}]";
    }

    /**
     * @param  array<string, mixed>  $shared
     */
    private function sharedMessageAuthor(array $shared): string
    {
        foreach (['author_name', 'author_subname'] as $field) {
            $name = Arr::get($shared, $field);

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        $authorId = Arr::get($shared, 'author_id');

        return is_string($authorId) && $authorId !== '' ? $this->names->userName($authorId) : 'desconocido';
    }

    /**
     * A share of a bot message (an alert, a payment notice) carries its
     * headline in `title` rather than in `text`.
     *
     * @param  array<string, mixed>  $shared
     * @return list<string>
     */
    private function sharedMessageBody(array $shared): array
    {
        $title = Arr::get($shared, 'title');

        return [
            ...(is_string($title) && $title !== '' ? $this->textLines($title) : []),
            ...$this->textLines((string) Arr::get($shared, 'text', '')),
            ...$this->fileLines($shared),
        ];
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
