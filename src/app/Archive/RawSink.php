<?php

namespace App\Archive;

/**
 * Where a render writes the archive's reading copy.
 *
 * A render streams one chunk per message into the sink, so the
 * copy never has to fit in memory whatever its layout on disk.
 */
interface RawSink
{
    public function write(string $markdown): void;

    /**
     * Finish the copy. The day is the newest one a --since-last run
     * appended, and null for any other run.
     */
    public function close(?string $appendedThrough = null): void;

    /**
     * Drop what an unfinished render left behind.
     */
    public function abort(): void;

    /**
     * The file or directory the copy lives in.
     */
    public function path(): string;
}
