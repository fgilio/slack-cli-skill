<?php

namespace App\Archive;

use RuntimeException;

/**
 * Splits a rendered stream of day sections into raw/ month files.
 *
 * A day header (`## YYYY-MM-DD`) of a new month closes the open month
 * and starts the next file: the month title, then the rest of the
 * header, then the days. Every file ends in exactly one newline, and
 * the blank line that separated two months in the stream goes with
 * neither. Joining the files' days with a newline between months
 * gives the stream back.
 *
 * The splitter holds one line at a time. A new month is written to a
 * .part file and renamed into place when the month closes.
 */
final class MonthSplitter implements RawSink
{
    private const DAY_HEADER = '/^## (\d{4}-\d{2})-\d{2}$/';

    /** @var resource|null */
    private $handle = null;

    private ?string $partPath = null;

    private int $pendingBlankLines = 0;

    private string $partialLine = '';

    private bool $lacksFinalNewline = false;

    private function __construct(
        private readonly string $dir,
        private readonly string $channelLabel,
        private readonly string $restOfHeader,
        private ?string $month,
    ) {}

    /**
     * Write every month of a stream from scratch. Text before the first
     * day header can only be the blank line the header already ends in.
     */
    public static function fresh(string $dir, ArchiveHeader $header): self
    {
        self::ensureDirectory($dir);

        return new self($dir, $header->channelLabel, $header->restOfHeader(), null);
    }

    /**
     * Append to the newest month file. Text before the first day header
     * continues that month's open day, and a new month copies its header.
     */
    public static function continuing(string $dir, string $month): self
    {
        $path = RawMonths::pathOf($dir, $month);
        $handle = fopen($path, 'rb');

        throw_if($handle === false, RuntimeException::class, "Unable to read the archive file at {$path}");

        $label = ArchiveHeader::labelOf((string) fgets($handle));
        $rest = '';

        while (($line = fgets($handle)) !== false && preg_match(self::DAY_HEADER, rtrim($line, "\n")) !== 1) {
            $rest .= $line;
        }

        fclose($handle);

        throw_if(
            $label === null || $line === false,
            RuntimeException::class,
            "Unable to read the archive header at the top of {$path}. A refresh copies it into each new month",
        );

        $splitter = new self($dir, $label, $rest, $month);

        clearstatcache();
        $size = (int) filesize($path);

        if ($size > 0) {
            $tail = fopen($path, 'rb');

            if ($tail !== false) {
                fseek($tail, $size - 1);
                $splitter->lacksFinalNewline = fread($tail, 1) !== "\n";
                fclose($tail);
            }
        }

        return $splitter;
    }

    public function write(string $markdown): void
    {
        $start = 0;
        $text = $this->partialLine.$markdown;

        while (($end = strpos($text, "\n", $start)) !== false) {
            $this->line(substr($text, $start, $end - $start + 1));
            $start = $end + 1;
        }

        $this->partialLine = substr($text, $start);
    }

    public function close(?string $appendedThrough = null): void
    {
        if ($this->partialLine !== '') {
            $line = $this->partialLine."\n";
            $this->partialLine = '';
            $this->line($line);
        }

        $this->closeMonth();
    }

    public function abort(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        if ($this->partPath !== null) {
            @unlink($this->partPath);
        }

        $this->handle = null;
        $this->partPath = null;
    }

    public function path(): string
    {
        return $this->dir;
    }

    private function line(string $line): void
    {
        if (str_starts_with($line, '## ') && preg_match(self::DAY_HEADER, rtrim($line, "\n"), $parts) === 1) {
            $this->dayHeader($parts[1], $line);

            return;
        }

        if ($line === "\n") {
            $this->pendingBlankLines++;

            return;
        }

        throw_if(
            $this->month === null,
            RuntimeException::class,
            "The render of {$this->dir} holds text before its first day header",
        );

        $this->emit(str_repeat("\n", $this->pendingBlankLines).$line);
        $this->pendingBlankLines = 0;
    }

    private function dayHeader(string $month, string $line): void
    {
        if ($month === $this->month) {
            $this->emit(str_repeat("\n", $this->pendingBlankLines).$line);
            $this->pendingBlankLines = 0;

            return;
        }

        throw_if(
            $this->month !== null && strcmp($month, $this->month) < 0,
            RuntimeException::class,
            "The render of {$this->dir} steps back from {$this->month} to {$month}. Its days must run oldest first",
        );

        $this->closeMonth();

        $this->month = $month;
        $this->partPath = RawMonths::pathOf($this->dir, $month).'.part';

        $handle = @fopen($this->partPath, 'wb');

        throw_if($handle === false, RuntimeException::class, "Unable to write the archive file at {$this->partPath}");

        $this->handle = $handle;
        $this->emit(ArchiveHeader::monthTitle($this->channelLabel, $month)."\n".$this->restOfHeader.$line);
    }

    /**
     * The blank lines that trail a month are dropped as it closes, so
     * every file ends in exactly one newline.
     */
    private function closeMonth(): void
    {
        $this->pendingBlankLines = 0;

        if ($this->handle === null) {
            return;
        }

        $flushed = fflush($this->handle);
        fclose($this->handle);
        $this->handle = null;

        if (! $flushed) {
            $this->abort();

            throw new RuntimeException("Unable to finish writing the render in {$this->dir}. Check the free disk space");
        }

        if ($this->partPath === null || $this->month === null) {
            return;
        }

        throw_unless(
            rename($this->partPath, RawMonths::pathOf($this->dir, $this->month)),
            RuntimeException::class,
            "Unable to move {$this->partPath} into place",
        );

        $this->partPath = null;
    }

    private function emit(string $text): void
    {
        if ($this->handle === null) {
            $this->openContinuedMonth();
        }

        // A full disk makes fwrite() write less than it was given. A cut
        // render must never be compared with raw/ or moved into its place.
        if (fwrite($this->handle, $text) !== strlen($text)) {
            $this->abort();

            throw new RuntimeException("Unable to write the render to {$this->dir}. Check the free disk space");
        }
    }

    /**
     * The newest month opens only once there is text for it, so a refresh
     * that brings nothing leaves it untouched.
     */
    private function openContinuedMonth(): void
    {
        $path = RawMonths::pathOf($this->dir, (string) $this->month);
        $handle = fopen($path, 'ab');

        throw_if($handle === false, RuntimeException::class, "Unable to write the archive file at {$path}");

        $this->handle = $handle;

        if ($this->lacksFinalNewline) {
            $this->lacksFinalNewline = false;
            $this->emit("\n");
        }
    }

    private static function ensureDirectory(string $dir): void
    {
        throw_if(
            ! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir),
            RuntimeException::class,
            "Unable to create the month directory at {$dir}",
        );
    }
}
