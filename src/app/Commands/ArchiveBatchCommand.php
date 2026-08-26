<?php

namespace App\Commands;

use App\Archive\ArchiveScan;
use App\Archive\BatchEntry;
use App\Archive\BatchManifest;
use App\Archive\BatchResult;
use App\Archive\BatchRunner;
use App\Archive\ScannedArchive;
use App\Archive\SlackEntryArchiver;

/**
 * Refresh a whole set of archives from one manifest.
 *
 * The single `archive` command handles one conversation. This walks a
 * list of them, keeps going when one fails, and reports what each
 * conversation gained.
 */
class ArchiveBatchCommand extends BaseSlackCommand
{
    protected $signature = 'archive:batch
        {paths?* : The manifest JSON file, or the directories to scan when --init is passed}
        {--init : Print a manifest built from the archives already in the given directories}
        {--since-last : Append only what is newer than the newest message each archive already holds}
        {--only= : Archive only the entries whose target or output directory matches this glob}';

    protected $description = 'Archive every conversation in a manifest, one after another';

    public function handle(): int
    {
        // Building a manifest reads the disk, never Slack.
        if ($this->option('init')) {
            $this->requiresAuth = false;
        }

        return parent::handle();
    }

    protected function doExecute(): int
    {
        /** @var list<string> $paths */
        $paths = (array) $this->argument('paths');

        if ($this->option('init')) {
            return $this->printManifest($paths);
        }

        if (count($paths) !== 1) {
            return $this->failWith('Give archive:batch exactly one manifest file. Pass --init instead to build one from the archives you already have');
        }

        $only = $this->option('only');

        $entries = BatchManifest::fromFile($paths[0])->select(is_string($only) ? $only : null);

        return $this->runBatch($entries);
    }

    /**
     * @param  list<BatchEntry>  $entries
     */
    private function runBatch(array $entries): int
    {
        $quiet = $this->wantsJson();

        $runner = new BatchRunner(new SlackEntryArchiver(
            $this->client,
            (bool) $this->option('since-last'),
        ));

        $results = $runner->run(
            $entries,
            onStart: $quiet ? null : function (BatchEntry $entry, int $position, int $total) {
                $this->output->write(sprintf('<fg=cyan>[%d/%d]</> %s → %s ... ', $position, $total, $entry->target, $entry->label()));
            },
            onFinish: $quiet ? null : function (BatchResult $result) {
                $color = $result->succeeded() ? 'green' : 'red';
                $this->line("<fg={$color}>{$result->outcome()}</>");
            },
            // The archiver's own page-by-page chatter would bury the entry
            // lines, so it only shows when the user asks for it.
            progress: $quiet || ! $this->output->isVerbose() ? null : fn (string $line) => $this->line("        <fg=gray>{$line}</>"),
        );

        $failed = BatchRunner::failureCount($results);

        if ($quiet) {
            $this->outputJson(array_map(fn (BatchResult $result) => $result->toArray(), $results));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->printSummary($results, $failed);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<BatchResult>  $results
     */
    private function printSummary(array $results, int $failed): void
    {
        $this->line('');

        $this->table(
            ['Target', 'Out', 'Messages', 'Replies', 'Threads', 'Status'],
            array_map(fn (BatchResult $result) => [
                $result->entry->target,
                $result->entry->label(),
                (string) ($result->summary->messages ?? 0),
                (string) ($result->summary->replies ?? 0),
                (string) ($result->summary->threads ?? 0),
                $result->status(),
            ], $results),
        );

        $total = count($results);

        if ($failed === 0) {
            $this->line("<fg=green>{$total} of {$total} archived.</>");

            return;
        }

        $this->line(sprintf('<fg=yellow>%d of %d archived, %d failed.</>', $total - $failed, $total, $failed));

        foreach ($results as $result) {
            if ($result->succeeded()) {
                continue;
            }

            $this->line("<fg=red>{$result->entry->target}: {$result->error}</>");
        }
    }

    /**
     * @param  list<string>  $dirs
     */
    private function printManifest(array $dirs): int
    {
        if ($dirs === []) {
            return $this->failWith('Give --init at least one directory to scan for archives');
        }

        $found = ArchiveScan::directories($dirs);

        if ($found === []) {
            return $this->failWith('Found no archives in those directories. An archive is a directory holding a messages.jsonl');
        }

        foreach ($found as $archive) {
            if ($archive->target !== null) {
                continue;
            }

            $this->output->getErrorStyle()->writeln("<comment>{$archive->note()}</comment>");
        }

        $entries = array_map(fn (ScannedArchive $archive) => $archive->toEntry()->toArray(), $found);

        if ($this->wantsJson()) {
            return $this->outputJson($entries);
        }

        $this->line((string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
