<?php

namespace App\Commands;

use App\Archive\ArchiveRequest;
use App\Archive\ChannelArchiver;
use App\Archive\DayBoundary;
use DateTimeZone;

/**
 * Archive a whole conversation to disk.
 *
 * Unlike messages:history, which answers a question and prints the answer,
 * this streams the conversation straight to files so channel size never
 * meets the PHP memory limit.
 */
class ArchiveCommand extends BaseSlackCommand
{
    protected $signature = 'archive
        {target : Channel ID (C…/G…/D…), #channel-name, or @username}
        {--out= : Directory to write messages.jsonl and raw.md into}
        {--after= : First day to archive, YYYY-MM-DD, inclusive}
        {--before= : Last day to archive, YYYY-MM-DD, inclusive}
        {--resume : Continue the interrupted run recorded in the output directory}
        {--since-last : Append only messages newer than the newest one already archived}
        {--no-threads : Skip thread replies}';

    protected $description = 'Archive a channel or DM to messages.jsonl and raw.md';

    protected function doExecute(): int
    {
        $outDir = (string) $this->option('out');

        if ($outDir === '') {
            return $this->failWith('The --out option is required. Give it the directory to write the archive into');
        }

        $timezone = $this->timezone();

        $request = new ArchiveRequest(
            channelId: $this->client->resolveConversationId((string) $this->argument('target')),
            outDir: rtrim($outDir, '/'),
            oldest: DayBoundary::start($this->option('after'), $timezone),
            latest: DayBoundary::end($this->option('before'), $timezone),
            includeThreads: ! $this->option('no-threads'),
            resume: (bool) $this->option('resume'),
            sinceLast: (bool) $this->option('since-last'),
        );

        $summary = (new ChannelArchiver($this->client))->archive(
            $request,
            $this->wantsJson() ? null : fn (string $line) => $this->line("<fg=gray>{$line}</>"),
        );

        if ($this->wantsJson()) {
            return $this->outputJson($summary->toArray());
        }

        $this->line('');
        $this->line("<fg=cyan>Archived {$summary->channelLabel}</>");
        $this->line("{$summary->messages} messages, {$summary->threads} threads, {$summary->replies} replies");

        if ($summary->firstDay !== null) {
            $this->line("Range: {$summary->firstDay} to {$summary->lastDay}");
        }

        $this->line($summary->messagesPath);
        $this->line($summary->markdownPath);

        return self::SUCCESS;
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone(
            $this->client->getAuthenticatedUserTimezone() ?? date_default_timezone_get()
        );
    }
}
