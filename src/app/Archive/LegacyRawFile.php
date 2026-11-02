<?php

namespace App\Archive;

use RuntimeException;

/**
 * The single raw.md an archive wrote before raw/ held one file per month.
 *
 * The file opens at a byte mark and is cut back to it first. A run that
 * died mid-render left a partial tail past its checkpoint's mark, and
 * everything after the mark is rendered again, so cutting back is what
 * makes resume lossless rather than duplicating.
 */
final class LegacyRawFile implements RawSink
{
    /**
     * @param  resource  $handle
     */
    private function __construct(
        private readonly string $path,
        private $handle,
    ) {}

    /**
     * Open the file at a byte mark. The header goes in only when the
     * mark is zero, since a non-empty file already carries one.
     */
    public static function openAt(string $path, int $offset, ?string $header = null): self
    {
        $handle = @fopen($path, $offset === 0 ? 'wb' : 'c+b');

        throw_if($handle === false, RuntimeException::class, "Unable to write the archive file at {$path}");

        if ($offset > 0) {
            ftruncate($handle, $offset);
            fseek($handle, $offset);
        }

        $file = new self($path, $handle);

        if ($offset > 0 || $header === null) {
            return $file;
        }

        try {
            $file->write($header);
        } catch (RuntimeException $e) {
            $file->abort();

            throw $e;
        }

        return $file;
    }

    public function write(string $markdown): void
    {
        // A full disk makes fwrite() write less than it was given. A cut
        // render must never be compared with raw.md or moved into its place.
        throw_if(
            fwrite($this->handle, $markdown) !== strlen($markdown),
            RuntimeException::class,
            "Unable to write the render to {$this->path}. Check the free disk space",
        );
    }

    public function close(?string $appendedThrough = null): void
    {
        $flushed = fflush($this->handle);
        fclose($this->handle);

        throw_unless($flushed, RuntimeException::class, "Unable to finish writing the render at {$this->path}. Check the free disk space");

        if ($appendedThrough !== null) {
            self::moveRangeTo($this->path, $appendedThrough);
        }
    }

    public function abort(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        @unlink($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Rewrite the title's closing date without loading the file into memory.
     */
    private static function moveRangeTo(string $path, string $lastDay): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        $line = fgets($handle);
        $title = $line === false ? null : ArchiveHeader::parseTitle($line);

        if ($title === null || $title[2] === $lastDay) {
            fclose($handle);

            return;
        }

        [$channelLabel, $from] = $title;

        $temp = $path.'.part';
        $out = fopen($temp, 'wb');

        if ($out === false) {
            fclose($handle);

            return;
        }

        $title = ArchiveHeader::title($channelLabel, $from, $lastDay)."\n";
        $complete = fwrite($out, $title) === strlen($title);

        while ($complete && ($line = fgets($handle)) !== false) {
            $complete = fwrite($out, $line) === strlen($line);
        }

        fclose($handle);
        $complete = fflush($out) && $complete;
        fclose($out);

        // The checkpoint is gone once the run ends, so a cut copy moved over
        // raw.md could not be resumed away.
        if (! $complete) {
            @unlink($temp);

            throw new RuntimeException("Unable to move the closing date of {$path} forward. Check the free disk space");
        }

        throw_unless(rename($temp, $path), RuntimeException::class, "Unable to move {$temp} into place at {$path}");
    }
}
