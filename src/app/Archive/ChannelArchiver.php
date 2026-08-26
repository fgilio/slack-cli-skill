<?php

namespace App\Archive;

use App\Services\SlackClient;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Streams a whole Slack conversation to disk.
 *
 * The run moves through four phases, each of which checkpoints the moment
 * its work reaches disk:
 *
 *   history  fetch conversations.history page by page, one file per page
 *   order    replay the pages backwards into a single oldest-first file
 *   threads  fetch conversations.replies for every message that has any
 *   render   append the ordered stream to messages.jsonl and raw.md
 *
 * Nothing accumulates in memory beyond one page and one thread, so the
 * cost of archiving a channel is the same whether it holds 500 messages
 * or half a million.
 */
final class ChannelArchiver
{
    private const CHECKPOINT_EVERY_THREADS = 10;

    public function __construct(private readonly SlackClient $client) {}

    public function archive(ArchiveRequest $request, ?Closure $progress = null): ArchiveSummary
    {
        $progress ??= static fn (string $line) => null;

        $this->prepareDirectories($request);

        $checkpoint = $this->openCheckpoint($request, $progress);

        if ($checkpoint->phase === ArchiveCheckpoint::PHASE_HISTORY) {
            $this->fetchHistory($request, $checkpoint, $progress);
        }

        if ($checkpoint->phase === ArchiveCheckpoint::PHASE_ORDER) {
            $this->orderPages($request, $checkpoint, $progress);
        }

        if ($checkpoint->phase === ArchiveCheckpoint::PHASE_THREADS) {
            $this->fetchThreads($request, $checkpoint, $progress);
        }

        $summary = $this->render($request, $checkpoint, $progress);

        $checkpoint->delete();
        $this->removeDirectory($request->workDir());

        return $summary;
    }

    // =========================================================================
    // Setup
    // =========================================================================

    private function prepareDirectories(ArchiveRequest $request): void
    {
        foreach ([$request->outDir, $request->workDir(), $request->workDir().'/pages', $request->workDir().'/threads'] as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Unable to create the output directory at {$dir}");
            }
        }
    }

    private function openCheckpoint(ArchiveRequest $request, Closure $progress): ArchiveCheckpoint
    {
        $stored = ArchiveCheckpoint::load($request->checkpointPath());

        if ($stored !== null && ! $request->resume) {
            throw new RuntimeException(
                "The directory {$request->outDir} holds an interrupted archive run. Pass --resume to continue it, or delete {$request->checkpointPath()} to start over"
            );
        }

        if ($stored !== null && ! $stored->covers($request->channelId, $request->oldest, $request->latest, $request->includeThreads, $request->sinceLast)) {
            throw new RuntimeException(
                "The checkpoint in {$request->outDir} belongs to a different channel or date window. Delete {$request->checkpointPath()} to start over"
            );
        }

        if ($stored !== null) {
            $progress("Resuming from phase '{$stored->phase}' ({$stored->pageCount} pages already fetched).");

            return $stored;
        }

        if ($request->resume) {
            throw new RuntimeException("There is no archive run to resume in {$request->outDir}. Drop --resume to start a fresh archive");
        }

        return $this->startFreshRun($request, $progress);
    }

    private function startFreshRun(ArchiveRequest $request, Closure $progress): ArchiveCheckpoint
    {
        clearstatcache();

        $archived = is_file($request->messagesPath()) ? (int) filesize($request->messagesPath()) : 0;

        if ($archived > 0 && ! $request->sinceLast) {
            throw new RuntimeException(
                "The directory {$request->outDir} already holds an archive. Pass --since-last to extend it forward, or point --out at an empty directory"
            );
        }

        $sinceTs = $request->sinceLast ? Jsonl::latestChannelTs($request->messagesPath()) : null;

        if ($sinceTs !== null) {
            $progress("Extending the archive forward from ts {$sinceTs}.");
        }

        $this->emptyDirectory($request->workDir().'/pages');
        $this->emptyDirectory($request->workDir().'/threads');

        $checkpoint = new ArchiveCheckpoint(
            path: $request->checkpointPath(),
            channelId: $request->channelId,
            oldest: $request->oldest,
            latest: $request->latest,
            threads: $request->includeThreads,
            sinceLast: $request->sinceLast,
            sinceTs: $sinceTs,
            messagesBytes: $archived,
            markdownBytes: is_file($request->markdownPath()) ? (int) filesize($request->markdownPath()) : 0,
        );

        $checkpoint->save();

        return $checkpoint;
    }

    /**
     * The floor an incremental run fetches from: whichever of --after and the
     * newest archived message sits later.
     */
    private function effectiveOldest(ArchiveCheckpoint $checkpoint): ?string
    {
        return $this->laterOf($checkpoint->oldest, $checkpoint->sinceTs);
    }

    private function laterOf(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return (float) $a >= (float) $b ? $a : $b;
    }

    // =========================================================================
    // Phase: history
    // =========================================================================

    private function fetchHistory(ArchiveRequest $request, ArchiveCheckpoint $checkpoint, Closure $progress): void
    {
        $pages = $this->client->historyPages(
            $request->channelId,
            $this->effectiveOldest($checkpoint),
            $request->latest,
            $checkpoint->cursor,
        );

        foreach ($pages as $page) {
            $messages = $page['messages'];

            Jsonl::writeAtomic(
                $this->pagePath($request, $checkpoint->pageCount),
                array_reverse($messages),
            );

            $checkpoint->pageCount++;
            $checkpoint->cursor = $page['next_cursor'];
            $checkpoint->save();

            $progress(sprintf('Fetched page %d (%d messages).', $checkpoint->pageCount, count($messages)));
        }

        $checkpoint->phase = ArchiveCheckpoint::PHASE_ORDER;
        $checkpoint->cursor = null;
        $checkpoint->save();
    }

    private function pagePath(ArchiveRequest $request, int $index): string
    {
        return sprintf('%s/pages/page-%06d.jsonl', $request->workDir(), $index);
    }

    // =========================================================================
    // Phase: order
    // =========================================================================

    private function orderPages(ArchiveRequest $request, ArchiveCheckpoint $checkpoint, Closure $progress): void
    {
        $paths = [];

        for ($index = 0; $index < $checkpoint->pageCount; $index++) {
            $paths[] = $this->pagePath($request, $index);
        }

        $ordered = Jsonl::concatReverse($paths, $this->historyPath($request).'.part');
        rename($this->historyPath($request).'.part', $this->historyPath($request));

        $progress("Ordered {$ordered} messages oldest first.");

        $checkpoint->phase = ArchiveCheckpoint::PHASE_THREADS;
        $checkpoint->threadLine = 0;
        $checkpoint->save();
    }

    private function historyPath(ArchiveRequest $request): string
    {
        return $request->workDir().'/history.jsonl';
    }

    // =========================================================================
    // Phase: threads
    // =========================================================================

    private function fetchThreads(ArchiveRequest $request, ArchiveCheckpoint $checkpoint, Closure $progress): void
    {
        if (! $request->includeThreads) {
            $checkpoint->phase = ArchiveCheckpoint::PHASE_RENDER;
            $checkpoint->save();

            return;
        }

        $fetched = 0;

        foreach (Jsonl::read($this->historyPath($request)) as $line => $message) {
            if ($line < $checkpoint->threadLine) {
                continue;
            }

            $checkpoint->threadLine = $line + 1;

            if (! $this->startsAThread($message)) {
                continue;
            }

            $ts = (string) $message['ts'];
            $path = $this->threadPath($request, $ts);

            if (! is_file($path)) {
                Jsonl::writeAtomic($path, $this->collectReplies($request->channelId, $ts));
            }

            $fetched++;

            if ($fetched % self::CHECKPOINT_EVERY_THREADS === 0) {
                $checkpoint->save();
                $progress("Fetched replies for {$fetched} threads.");
            }
        }

        $progress("Fetched replies for {$fetched} threads.");

        $checkpoint->phase = ArchiveCheckpoint::PHASE_RENDER;
        $checkpoint->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectReplies(string $channelId, string $threadTs): array
    {
        $replies = [];

        foreach ($this->client->repliesPages($channelId, $threadTs) as $page) {
            foreach ($page['messages'] as $message) {
                // conversations.replies leads with the parent on every page.
                if (($message['ts'] ?? null) === $threadTs) {
                    continue;
                }

                $replies[] = $message;
            }
        }

        return $replies;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function startsAThread(array $message): bool
    {
        return ($message['thread_ts'] ?? null) === ($message['ts'] ?? null)
            && (int) ($message['reply_count'] ?? 0) > 0;
    }

    /**
     * A reply sent "also to the channel" comes back from both
     * conversations.history and conversations.replies. It renders under
     * its parent, so the copy in the channel stream is dropped.
     *
     * @param  array<string, mixed>  $message
     */
    private function isThreadReply(array $message): bool
    {
        $threadTs = $message['thread_ts'] ?? null;

        return is_string($threadTs) && $threadTs !== ($message['ts'] ?? null);
    }

    private function threadPath(ArchiveRequest $request, string $ts): string
    {
        return $request->workDir().'/threads/'.$ts.'.jsonl';
    }

    // =========================================================================
    // Phase: render
    // =========================================================================

    private function render(ArchiveRequest $request, ArchiveCheckpoint $checkpoint, Closure $progress): ArchiveSummary
    {
        $names = $this->names();
        $timezone = $this->timezone();

        $renderer = new MarkdownRenderer(
            new SlackMarkup($names),
            $names,
            $timezone,
            // An incremental run continues under the day header the previous
            // run left open, so a same-day refresh does not repeat it.
            $checkpoint->sinceTs !== null ? MarkdownRenderer::dayOf($checkpoint->sinceTs, $timezone) : null,
        );

        $messages = $this->openAt($request->messagesPath(), $checkpoint->messagesBytes);
        $markdown = $this->openAt($request->markdownPath(), $checkpoint->markdownBytes);

        if ($checkpoint->markdownBytes === 0) {
            fwrite($markdown, $renderer->header(
                $this->channelLabel($request->channelId),
                ...$this->windowLabels($request),
            ));
        }

        $written = 0;
        $threads = 0;
        $replyCount = 0;
        $skipped = 0;
        $lastTs = null;
        $firstDay = null;
        $lastDay = null;

        foreach (Jsonl::read($this->historyPath($request)) as $message) {
            $ts = (string) Arr::get($message, 'ts', '');

            if ($ts === '' || $ts === $lastTs) {
                $skipped++;

                continue;
            }

            if ($checkpoint->sinceTs !== null && (float) $ts <= (float) $checkpoint->sinceTs) {
                $skipped++;

                continue;
            }

            if ($request->includeThreads && $this->isThreadReply($message)) {
                $skipped++;

                continue;
            }

            $lastTs = $ts;
            $replies = $this->repliesFor($request, $message);

            fwrite($messages, Jsonl::encode($message)."\n");

            foreach ($replies as $reply) {
                fwrite($messages, Jsonl::encode($reply)."\n");
            }

            fwrite($markdown, $renderer->message($message, $replies));

            $written++;
            $replyCount += count($replies);
            $threads += $replies === [] ? 0 : 1;

            $firstDay ??= $renderer->day($ts);
            $lastDay = $renderer->day($ts);
        }

        fclose($messages);
        fclose($markdown);

        if ($checkpoint->sinceTs !== null && $lastDay !== null) {
            $this->refreshHeaderRange($request->markdownPath(), $lastDay);
        }

        $progress("Wrote {$written} messages and {$replyCount} replies.");

        return new ArchiveSummary(
            channelId: $request->channelId,
            channelLabel: $this->channelLabel($request->channelId),
            messages: $written,
            threads: $threads,
            replies: $replyCount,
            skipped: $skipped,
            firstDay: $firstDay,
            lastDay: $lastDay,
            messagesPath: $request->messagesPath(),
            markdownPath: $request->markdownPath(),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    private function repliesFor(ArchiveRequest $request, array $message): array
    {
        if (! $request->includeThreads || ! $this->startsAThread($message)) {
            return [];
        }

        $path = $this->threadPath($request, (string) $message['ts']);
        $replies = [];
        $seen = [];

        foreach (Jsonl::read($path) as $reply) {
            $ts = (string) Arr::get($reply, 'ts', '');

            if ($ts === '' || isset($seen[$ts])) {
                continue;
            }

            $seen[$ts] = true;
            $replies[] = $reply;
        }

        return $replies;
    }

    /**
     * The header's date range comes from the messages actually archived, and
     * falls back to the requested window when the channel had none.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function windowLabels(ArchiveRequest $request): array
    {
        [$first, $last] = Jsonl::boundingTimestamps($this->historyPath($request));

        return [
            $this->dayLabel($first ?? $request->oldest),
            $this->dayLabel($last ?? $request->latest),
            $this->client->getWorkspaceName(),
            $this->channelMeta($request->channelId),
        ];
    }

    private function dayLabel(?string $ts): string
    {
        return $ts !== null
            ? MarkdownRenderer::dayOf($ts, $this->timezone())
            : (new DateTimeImmutable('now', $this->timezone()))->format('Y-m-d');
    }

    /**
     * Rewrite the header's closing date without loading the file into memory.
     */
    private function refreshHeaderRange(string $path, string $lastDay): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        $header = fgets($handle);

        if ($header === false || ! preg_match('/^(# .+ — Dump completo \(\d{4}-\d{2}-\d{2} a )(\d{4}-\d{2}-\d{2})(\)\R?)$/u', $header, $parts)) {
            fclose($handle);

            return;
        }

        if ($parts[2] === $lastDay) {
            fclose($handle);

            return;
        }

        $temp = $path.'.part';
        $out = fopen($temp, 'wb');

        if ($out === false) {
            fclose($handle);

            return;
        }

        fwrite($out, $parts[1].$lastDay.$parts[3]);

        while (($line = fgets($handle)) !== false) {
            fwrite($out, $line);
        }

        fclose($handle);
        fclose($out);
        rename($temp, $path);
    }

    // =========================================================================
    // Channel and timezone metadata
    // =========================================================================

    private ?string $channelLabel = null;

    private ?string $channelMeta = null;

    private ?DateTimeZone $timezone = null;

    private ?SlackNameResolver $names = null;

    private function names(): SlackNameResolver
    {
        return $this->names ??= new SlackNameResolver($this->client);
    }

    private function timezone(): DateTimeZone
    {
        return $this->timezone ??= new DateTimeZone(
            $this->client->getAuthenticatedUserTimezone() ?? date_default_timezone_get()
        );
    }

    private function channelLabel(string $channelId): string
    {
        $this->loadChannelMetadata($channelId);

        return (string) $this->channelLabel;
    }

    private function channelMeta(string $channelId): string
    {
        $this->loadChannelMetadata($channelId);

        return (string) $this->channelMeta;
    }

    private function loadChannelMetadata(string $channelId): void
    {
        if ($this->channelLabel !== null) {
            return;
        }

        $info = $this->client->getChannelInfo($channelId);

        if ($info === null) {
            $this->channelLabel = $channelId;
            $this->channelMeta = 'Conversación sin metadatos.';

            return;
        }

        if ($info->get('is_im')) {
            $name = $this->names()->userName((string) $info->get('user', ''));
            $this->channelLabel = "DM con {$name}";
            $this->channelMeta = "Mensaje directo con {$name}.";

            return;
        }

        $name = (string) $info->get('name', $channelId);
        $this->channelLabel = "#{$name}";

        if ($info->get('is_mpim')) {
            $this->channelMeta = 'Grupo privado.';

            return;
        }

        $kind = $info->get('is_private') ? 'Canal privado' : 'Canal público';
        $members = (int) $info->get('num_members', 0);

        $this->channelMeta = $members > 0
            ? "{$kind}, {$members} miembros."
            : "{$kind}.";
    }

    // =========================================================================
    // Filesystem helpers
    // =========================================================================

    /**
     * @return resource
     */
    private function openAt(string $path, int $offset)
    {
        if (! is_file($path)) {
            touch($path);
        }

        $handle = fopen($path, 'r+b');

        if ($handle === false) {
            throw new RuntimeException("Unable to write the archive file at {$path}");
        }

        // A run that died mid-render left a partial tail. Everything after the
        // checkpoint's mark is rewritten from the ordered history, so cutting
        // back to it is what makes resume lossless rather than duplicating.
        ftruncate($handle, $offset);
        fseek($handle, $offset);

        return $handle;
    }

    private function emptyDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
