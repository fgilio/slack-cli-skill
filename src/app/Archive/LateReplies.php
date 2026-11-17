<?php

namespace App\Archive;

use App\Services\SlackClient;
use Closure;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Brings in the replies that reached an already archived thread after the
 * run that archived it.
 *
 * A --since-last run fetches the messages newer than the newest one on
 * disk, and the threads of those messages. A reply posted days later to
 * an older thread is in neither, so without this pass it never reaches
 * the archive. The pass reads the conversation again from a floor some
 * days back, asks only the threads whose reply count or latest reply
 * moved for their replies, and folds the new ones in right after the
 * replies already archived under their parent. raw/ is then rendered
 * again from messages.jsonl, which only changes the months that gained
 * a reply.
 */
final class LateReplies
{
    public const DEFAULT_LOOKBACK_DAYS = 30;

    public function __construct(
        private readonly SlackClient $client,
        private readonly NameResolver $names,
    ) {}

    /**
     * @return array{threads: int, replies: int}
     */
    public function catchUp(string $outDir, string $channelId, string $floor, ?Closure $progress = null): array
    {
        $progress ??= static fn (string $line) => null;
        $messagesPath = ArchiveRequest::messagesPathIn($outDir);

        [$archived, $known] = $this->archivedThreads($outDir, $messagesPath, $floor);

        if ($archived === []) {
            return ['threads' => 0, 'replies' => 0];
        }

        $late = $this->fetchLateReplies($channelId, $floor, $archived, $known);

        if ($late === []) {
            return ['threads' => 0, 'replies' => 0];
        }

        $replies = array_sum(array_map('count', $late));

        $this->fold($messagesPath, $late);
        (new MarkdownRebuilder($this->names))->rebuild($outDir);

        $progress(sprintf('Added %d late %s to %d %s.', $replies, $replies === 1 ? 'reply' : 'replies', count($late), count($late) === 1 ? 'thread' : 'threads'));

        return ['threads' => count($late), 'replies' => $replies];
    }

    /**
     * Every parent at or after the floor whose replies the archive nests,
     * with the ts of each reply it already holds, and every ts the archive
     * holds from the floor on. A reply is only new when it is in neither,
     * so nothing already on disk is ever written twice.
     *
     * @return array{0: array<string, array<string, true>>, 1: array<string, true>}
     */
    private function archivedThreads(string $outDir, string $messagesPath, string $floor): array
    {
        $metadata = ArchiveMetadata::load($outDir) ?? new ArchiveMetadata('', '');
        $threads = [];
        $known = [];

        foreach (Jsonl::read($messagesPath) as $message) {
            $ts = (string) Arr::get($message, 'ts', '');

            if ($ts !== '' && Jsonl::compareTimestamps($ts, $floor) >= 0) {
                $known[$ts] = true;
            }

            $threadTs = Arr::get($message, 'thread_ts');

            if (is_string($threadTs) && $threadTs !== $ts) {
                if (isset($threads[$threadTs])) {
                    $threads[$threadTs][$ts] = true;
                }

                continue;
            }

            if ($ts !== '' && Jsonl::compareTimestamps($ts, $floor) >= 0 && $metadata->nestsRepliesAt($ts)) {
                $threads[$ts] = [];
            }
        }

        return [$threads, $known];
    }

    /**
     * conversations.history hands back each parent with its current reply
     * count and latest reply, so only a thread that moved costs a call to
     * conversations.replies.
     *
     * @param  array<string, array<string, true>>  $archived
     * @param  array<string, true>  $known
     * @return array<string, list<array<string, mixed>>>
     */
    private function fetchLateReplies(string $channelId, string $floor, array $archived, array $known): array
    {
        $late = [];

        foreach ($this->client->historyPages($channelId, $floor) as $page) {
            foreach ($page['messages'] as $parent) {
                $ts = (string) Arr::get($parent, 'ts', '');

                if (! isset($archived[$ts]) || ! $this->moved($parent, $archived[$ts])) {
                    continue;
                }

                $new = $this->newReplies($channelId, $ts, $known);

                if ($new !== []) {
                    $late[$ts] = $new;
                }
            }
        }

        return $late;
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  array<string, true>  $held
     */
    private function moved(array $parent, array $held): bool
    {
        if ((int) Arr::get($parent, 'reply_count', 0) > count($held)) {
            return true;
        }

        $latest = Arr::get($parent, 'latest_reply');

        if (! is_string($latest) || $latest === '') {
            return false;
        }

        foreach (array_keys($held) as $ts) {
            if (Jsonl::compareTimestamps((string) $ts, $latest) >= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, true>  $known
     * @return list<array<string, mixed>>
     */
    private function newReplies(string $channelId, string $threadTs, array $known): array
    {
        $new = [];

        foreach ($this->client->repliesPages($channelId, $threadTs) as $page) {
            foreach ($page['messages'] as $reply) {
                $ts = (string) Arr::get($reply, 'ts', '');

                // conversations.replies leads with the parent on every page.
                if ($ts === '' || $ts === $threadTs || isset($known[$ts]) || isset($new[$ts])) {
                    continue;
                }

                $new[$ts] = $reply;
            }
        }

        return array_values(Jsonl::sortByTimestamp(array_values($new)));
    }

    /**
     * messages.jsonl keeps each thread's replies right after its parent,
     * which is what the render reads them back by. The file is written
     * whole beside the old one and moved into place, so a run killed
     * here leaves the archive as it was.
     *
     * @param  array<string, list<array<string, mixed>>>  $late
     */
    private function fold(string $messagesPath, array $late): void
    {
        $temp = $messagesPath.'.part';
        $handle = fopen($temp, 'wb');

        throw_if($handle === false, RuntimeException::class, "Unable to write {$temp}");

        $block = null;
        $replies = [];

        foreach (Jsonl::read($messagesPath) as $message) {
            $threadTs = Arr::get($message, 'thread_ts');

            if ($block !== null && is_string($threadTs) && $threadTs === $block && $threadTs !== Arr::get($message, 'ts')) {
                $replies[] = $message;

                continue;
            }

            $this->writeBlock($handle, $block, $replies, $late);

            $block = (string) Arr::get($message, 'ts', '');
            $replies = [$message];
        }

        $this->writeBlock($handle, $block, $replies, $late);
        fclose($handle);

        rename($temp, $messagesPath);
    }

    /**
     * @param  resource  $handle
     * @param  list<array<string, mixed>>  $lines  The parent, then the replies already archived.
     * @param  array<string, list<array<string, mixed>>>  $late
     */
    private function writeBlock($handle, ?string $parentTs, array $lines, array $late): void
    {
        if ($parentTs === null) {
            return;
        }

        $parent = array_shift($lines);
        $replies = isset($late[$parentTs]) ? Jsonl::sortByTimestamp([...$lines, ...$late[$parentTs]]) : $lines;

        foreach ([$parent, ...$replies] as $line) {
            fwrite($handle, Jsonl::encode($line)."\n");
        }
    }
}
