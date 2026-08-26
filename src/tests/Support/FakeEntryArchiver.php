<?php

namespace Tests\Support;

use App\Archive\ArchiveSummary;
use App\Archive\BatchEntry;
use App\Archive\EntryArchiver;
use Closure;
use RuntimeException;

/**
 * An entry archiver driven by a script keyed on target.
 *
 * A script value is either the message count the entry produced or the
 * message an entry that fails should throw.
 */
final class FakeEntryArchiver implements EntryArchiver
{
    /** @var list<string> */
    public array $archived = [];

    /**
     * @param  array<string, int|string>  $script
     */
    public function __construct(private readonly array $script = []) {}

    public function archive(BatchEntry $entry, ?Closure $progress = null): ArchiveSummary
    {
        $this->archived[] = $entry->target;

        if ($progress !== null) {
            $progress("working on {$entry->target}");
        }

        $outcome = $this->script[$entry->target] ?? 0;

        throw_if(is_string($outcome), RuntimeException::class, $outcome);

        return new ArchiveSummary(
            channelId: 'C'.strtoupper(ltrim($entry->target, '@#')),
            channelLabel: $entry->target,
            messages: $outcome,
            threads: $outcome > 0 ? 1 : 0,
            replies: $outcome > 0 ? 2 : 0,
            skipped: 0,
            firstDay: $outcome > 0 ? '2026-08-01' : null,
            lastDay: $outcome > 0 ? '2026-08-02' : null,
            messagesPath: $entry->outDir.'/messages.jsonl',
            markdownPath: $entry->outDir.'/raw.md',
        );
    }
}
