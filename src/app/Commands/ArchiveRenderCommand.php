<?php

namespace App\Commands;

use App\Archive\MarkdownRebuilder;
use App\Archive\RawMonths;
use App\Archive\SlackNameResolver;

/**
 * Render an archive's raw/ month files from the messages.jsonl beside it.
 *
 * Fetches no messages. People's names still go through the same
 * resolver `archive` uses, which reads the cached user directory.
 */
class ArchiveRenderCommand extends BaseSlackCommand
{
    protected $signature = 'archive:render
        {dir : An archive directory, holding messages.jsonl and raw/ (or a single raw.md, which moves to raw/)}
        {--check : Compare raw/ (or a single raw.md) with a fresh render and write nothing, exiting 1 when they differ}';

    protected $description = 'Render raw/ again from an archive directory\'s messages.jsonl';

    protected function doExecute(): int
    {
        $outDir = rtrim((string) $this->argument('dir'), '/');

        if (! is_dir($outDir)) {
            return $this->failWith("Unable to find the archive directory {$outDir}");
        }

        $rebuilder = new MarkdownRebuilder(new SlackNameResolver($this->client));

        if ($this->option('check')) {
            return $this->check($rebuilder, $outDir);
        }

        $moved = RawMonths::isLegacy($outDir);
        $summary = $rebuilder->rebuild($outDir);

        if ($this->wantsJson()) {
            return $this->outputJson($summary->toArray());
        }

        $this->line("<fg=cyan>Rendered {$summary->channelLabel}</>");
        $this->line("{$summary->messages} messages, {$summary->threads} threads, {$summary->replies} replies");

        if ($summary->firstDay !== null) {
            $this->line("Range: {$summary->firstDay} to {$summary->lastDay}");
        }

        if ($moved) {
            $this->line('Moved raw.md to raw/, one file per month');
        }

        $this->line($summary->markdownPath);

        return self::SUCCESS;
    }

    private function check(MarkdownRebuilder $rebuilder, string $outDir): int
    {
        $copy = RawMonths::isLegacy($outDir) ? 'raw.md' : 'raw/';
        $difference = $rebuilder->firstDifference($outDir);

        if ($difference !== null) {
            return $this->failWith(
                "{$copy} in {$outDir} differs from a render of its messages.jsonl: {$difference->describe()}. archive:render without --check rewrites it and drops any edit made only to {$copy}",
                $difference->toArray(),
            );
        }

        if ($this->wantsJson()) {
            return $this->outputJson(['matches' => true]);
        }

        $this->line("{$copy} in {$outDir} matches its messages.jsonl");

        return self::SUCCESS;
    }
}
