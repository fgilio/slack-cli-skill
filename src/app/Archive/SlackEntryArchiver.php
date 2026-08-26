<?php

namespace App\Archive;

use App\Services\SlackClient;
use Closure;
use DateTimeZone;

/**
 * Runs a manifest entry through the same path the single `archive`
 * command takes: resolve the target, turn its days into Slack
 * timestamps, hand the request to a ChannelArchiver.
 */
final class SlackEntryArchiver implements EntryArchiver
{
    private ?DateTimeZone $timezone = null;

    public function __construct(
        private readonly SlackClient $client,
        private readonly bool $sinceLast = false,
    ) {}

    public function archive(BatchEntry $entry, ?Closure $progress = null): ArchiveSummary
    {
        $timezone = $this->timezone();

        $request = new ArchiveRequest(
            channelId: $this->client->resolveConversationId($entry->target),
            outDir: $entry->outDir,
            oldest: DayBoundary::start($entry->after, $timezone),
            latest: DayBoundary::end($entry->before, $timezone),
            includeThreads: ! $entry->noThreads,
            // A batch runs unattended, so a directory an earlier batch left
            // mid-run continues instead of stopping to ask for --resume.
            resume: is_file(ArchiveRequest::checkpointPathIn($entry->outDir)),
            sinceLast: $this->sinceLast,
        );

        // ChannelArchiver caches the channel's metadata, so every entry
        // needs one of its own.
        return (new ChannelArchiver($this->client))->archive($request, $progress);
    }

    private function timezone(): DateTimeZone
    {
        return $this->timezone ??= new DateTimeZone(
            $this->client->getAuthenticatedUserTimezone() ?? date_default_timezone_get()
        );
    }
}
