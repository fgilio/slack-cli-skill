<?php

namespace App\Commands;

use App\Archive\MarkdownRebuilder;
use App\Archive\SlackNameResolver;

/**
 * Rebuild an archive's raw.md from the messages.jsonl beside it.
 *
 * Fetches no messages. People's names still go through the same
 * resolver `archive` uses, which reads the cached user directory.
 */
class ArchiveRenderCommand extends BaseSlackCommand
{
    protected $signature = 'archive:render
        {dir : An archive directory, holding messages.jsonl and raw.md}
        {--check : Compare raw.md with a fresh render and write nothing, exiting 1 when they differ}';

    protected $description = 'Render raw.md again from an archive directory\'s messages.jsonl';

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

        $summary = $rebuilder->rebuild($outDir);

        if ($this->wantsJson()) {
            return $this->outputJson($summary->toArray());
        }

        $this->line("<fg=cyan>Rendered {$summary->channelLabel}</>");
        $this->line("{$summary->messages} messages, {$summary->threads} threads, {$summary->replies} replies");

        if ($summary->firstDay !== null) {
            $this->line("Range: {$summary->firstDay} to {$summary->lastDay}");
        }

        $this->line($summary->markdownPath);

        return self::SUCCESS;
    }

    private function check(MarkdownRebuilder $rebuilder, string $outDir): int
    {
        $line = $rebuilder->firstStaleLine($outDir);

        if ($line !== null) {
            return $this->failWith(
                "raw.md in {$outDir} differs from a render of its messages.jsonl, first at line {$line}. archive:render without --check rewrites it and drops any edit made only to raw.md",
                ['line' => $line],
            );
        }

        if ($this->wantsJson()) {
            return $this->outputJson(['matches' => true]);
        }

        $this->line("raw.md in {$outDir} matches its messages.jsonl");

        return self::SUCCESS;
    }
}
