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
        {dir : An archive directory, holding messages.jsonl and raw.md}';

    protected $description = 'Rebuild raw.md from an archive directory\'s messages.jsonl';

    protected function doExecute(): int
    {
        $outDir = rtrim((string) $this->argument('dir'), '/');

        if (! is_dir($outDir)) {
            return $this->failWith("Unable to find the archive directory {$outDir}");
        }

        $summary = (new MarkdownRebuilder(new SlackNameResolver($this->client)))->rebuild($outDir);

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
}
