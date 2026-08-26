<?php

namespace App\Archive;

use Closure;
use Throwable;

/**
 * Walks a manifest one entry at a time.
 *
 * Entries run in manifest order and never in parallel: Slack rate limits
 * are per workspace, so two conversations fetched at once only make each
 * other wait.
 */
final class BatchRunner
{
    public function __construct(private readonly EntryArchiver $archiver) {}

    /**
     * @param  list<BatchEntry>  $entries
     * @param  null|Closure(BatchEntry, int, int): void  $onStart
     * @param  null|Closure(BatchResult, int, int): void  $onFinish
     * @param  null|Closure(string): void  $progress
     * @return list<BatchResult>
     */
    public function run(
        array $entries,
        ?Closure $onStart = null,
        ?Closure $onFinish = null,
        ?Closure $progress = null,
    ): array {
        $total = count($entries);
        $results = [];

        foreach ($entries as $index => $entry) {
            $position = $index + 1;

            if ($onStart !== null) {
                $onStart($entry, $position, $total);
            }

            // One conversation failing is a row in the summary, not the end
            // of the batch: the entries after it are unrelated channels.
            try {
                $result = BatchResult::archived($entry, $this->archiver->archive($entry, $progress));
            } catch (Throwable $e) {
                $result = BatchResult::failed($entry, $e->getMessage());
            }

            if ($onFinish !== null) {
                $onFinish($result, $position, $total);
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param  list<BatchResult>  $results
     */
    public static function failureCount(array $results): int
    {
        return count(array_filter($results, fn (BatchResult $result) => ! $result->succeeded()));
    }
}
