<?php

namespace App\Archive;

use LogicException;

/**
 * The four lines that open each raw/ month file, or the single raw.md.
 *
 * The header is written once by the first run and then only copied, so
 * a rebuild reads the channel, workspace and timezone back from it
 * instead of asking Slack for values that may have changed since.
 *
 * A month file's title names its month. The single raw.md title names
 * the archive's first and last day, so only that header carries them.
 */
final class ArchiveHeader
{
    private const TITLE = '/^# (.+) — Dump completo \((\d{4}-\d{2}-\d{2}) a (\d{4}-\d{2}-\d{2})\)$/u';

    private const MONTH_TITLE = '/^# (.+) — (\d{4}-\d{2})$/u';

    private const WORKSPACE = '/^Workspace: (.+?)\. (.+)$/u';

    private const TIMEZONE = '/^Horarios en (\S+) \.$/u';

    public function __construct(
        public readonly string $channelLabel,
        public readonly string $workspace,
        public readonly string $channelMeta,
        public readonly string $timezone,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    public static function title(string $channelLabel, string $from, string $to): string
    {
        return "# {$channelLabel} — Dump completo ({$from} a {$to})";
    }

    public static function monthTitle(string $channelLabel, string $month): string
    {
        return "# {$channelLabel} — {$month}";
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

    /**
     * The channel label of either title.
     */
    public static function labelOf(string $line): ?string
    {
        $line = rtrim($line, "\r\n");

        return preg_match(self::TITLE, $line, $parts) === 1 || preg_match(self::MONTH_TITLE, $line, $parts) === 1
            ? $parts[1]
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

        $label = self::labelOf($lines[0]);

        if ($label === null
            || preg_match(self::WORKSPACE, $lines[2], $workspace) !== 1
            || preg_match(self::TIMEZONE, $lines[3], $timezone) !== 1) {
            return null;
        }

        $range = self::parseTitle($lines[0]);

        return new self($label, $workspace[1], $workspace[2], $timezone[1], $range[1] ?? null, $range[2] ?? null);
    }

    /**
     * What follows the title in a month file, up to its first day header:
     * the blank line, the workspace and timezone lines, and the blank line
     * that opens the days.
     */
    public function restOfHeader(): string
    {
        return "\nWorkspace: {$this->workspace}. {$this->channelMeta}\n"
            ."Horarios en {$this->timezone} .\n\n";
    }

    /**
     * The single raw.md header. The blank line before the first day comes
     * from the renderer's first day header.
     */
    public function toMarkdown(): string
    {
        throw_if(
            $this->from === null || $this->to === null,
            LogicException::class,
            "The header of {$this->channelLabel} carries no date range, so it cannot open a single raw.md",
        );

        return self::title($this->channelLabel, $this->from, $this->to)."\n".substr($this->restOfHeader(), 0, -1);
    }
}
