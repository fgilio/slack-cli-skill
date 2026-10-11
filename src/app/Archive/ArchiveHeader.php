<?php

namespace App\Archive;

/**
 * The four lines that open raw.md.
 *
 * The header is written once by the first run and then only edited, so
 * a rebuild reads the channel, workspace and timezone back from it
 * instead of asking Slack for values that may have changed since.
 */
final class ArchiveHeader
{
    private const TITLE = '/^# (.+) — Dump completo \((\d{4}-\d{2}-\d{2}) a (\d{4}-\d{2}-\d{2})\)$/u';

    private const WORKSPACE = '/^Workspace: (.+?)\. (.+)$/u';

    private const TIMEZONE = '/^Horarios en (\S+) \.$/u';

    public function __construct(
        public readonly string $channelLabel,
        public readonly string $from,
        public readonly string $to,
        public readonly string $workspace,
        public readonly string $channelMeta,
        public readonly string $timezone,
    ) {}

    public static function title(string $channelLabel, string $from, string $to): string
    {
        return "# {$channelLabel} — Dump completo ({$from} a {$to})";
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null The channel label, first day and last day.
     */
    public static function parseTitle(string $line): ?array
    {
        return preg_match(self::TITLE, rtrim($line, "\r\n"), $parts) === 1
            ? [$parts[1], $parts[2], $parts[3]]
            : null;
    }

    public static function read(string $markdownPath): ?self
    {
        if (! is_file($markdownPath)) {
            return null;
        }

        $handle = fopen($markdownPath, 'rb');

        if ($handle === false) {
            return null;
        }

        $lines = [];

        while (count($lines) < 4 && ($line = fgets($handle)) !== false) {
            $lines[] = rtrim($line, "\r\n");
        }

        fclose($handle);

        return self::parse($lines);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function parse(array $lines): ?self
    {
        if (count($lines) < 4 || $lines[1] !== '') {
            return null;
        }

        $title = self::parseTitle($lines[0]);

        if ($title === null
            || preg_match(self::WORKSPACE, $lines[2], $workspace) !== 1
            || preg_match(self::TIMEZONE, $lines[3], $timezone) !== 1) {
            return null;
        }

        return new self($title[0], $title[1], $title[2], $workspace[1], $workspace[2], $timezone[1]);
    }

    public function toMarkdown(): string
    {
        return self::title($this->channelLabel, $this->from, $this->to)."\n\n"
            ."Workspace: {$this->workspace}. {$this->channelMeta}\n"
            ."Horarios en {$this->timezone} .\n";
    }
}
