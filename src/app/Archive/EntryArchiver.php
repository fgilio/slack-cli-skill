<?php

namespace App\Archive;

use Closure;

/**
 * Archives one manifest entry.
 */
interface EntryArchiver
{
    public function archive(BatchEntry $entry, ?Closure $progress = null): ArchiveSummary;
}
