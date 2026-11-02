<?php

namespace App\Archive;

use DateTimeZone;
use Generator;
use Illuminate\Support\Arr;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Rebuilds an archive's raw/ month files from its messages.jsonl.
 *
 * Nothing is fetched. The messages come from messages.jsonl, and the
 * channel, workspace and timezone from the header the archive run
 * wrote, so the result is what `archive` would write for the same
 * messages under the renderer as it stands today.
 *
 * A directory still on the single raw.md moves to raw/ when rebuilt,
 * and is checked against the single file until then.
 */
final class MarkdownRebuilder
{
    private const EMPTY_SUMMARY = ['messages' => 0, 'threads' => 0, 'replies' => 0, 'first_day' => null, 'last_day' => null];

    public function __construct(private readonly NameResolver $names) {}

    /**
     * Render every month into a directory beside raw/, then move the
     * months into place and drop the ones no message falls in anymore,
     * along with the single raw.md. A render that fails leaves raw/ and
     * raw.md as they were.
     */
    public function rebuild(string $outDir): ArchiveSummary
    {
        [$header, $metadata, $messagesPath] = $this->sources($outDir);
        $staging = $outDir.'/.raw-render';

        RawMonths::remove($staging);

        try {
            $summary = $this->renderMonths($header, $metadata, $messagesPath, $staging);
        } catch (Throwable $e) {
            RawMonths::remove($staging);

            throw $e;
        }

        RawMonths::replace(RawMonths::dirIn($outDir), $staging);

        if (is_file(RawMonths::legacyPathIn($outDir))) {
            unlink(RawMonths::legacyPathIn($outDir));
        }

        return new ArchiveSummary(
            channelId: $metadata->channelId ?? $header->channelLabel ?? basename($outDir),
            channelLabel: $header->channelLabel ?? $metadata->channelLabel ?? basename($outDir),
            messages: $summary['messages'],
            threads: $summary['threads'],
            replies: $summary['replies'],
            skipped: 0,
            firstDay: $summary['first_day'],
            lastDay: $summary['last_day'],
            messagesPath: $messagesPath,
            markdownPath: RawMonths::dirIn($outDir),
        );
    }

    /**
     * Where the archive's reading copy first parts from a render of its
     * messages.jsonl, or null when they match, as they must after both
     * are redacted. The render goes to a temporary location, so the
     * archive is untouched.
     */
    public function firstDifference(string $outDir): ?RenderDifference
    {
        [$header, $metadata, $messagesPath] = $this->sources($outDir);

        if (RawMonths::isLegacy($outDir)) {
            return $this->legacyDifference($outDir, $header, $metadata, $messagesPath);
        }

        $rendered = sys_get_temp_dir().'/slack-render-'.bin2hex(random_bytes(6));

        try {
            $this->renderMonths($header, $metadata, $messagesPath, $rendered);

            return RawMonths::firstDifference(RawMonths::dirIn($outDir), $rendered);
        } finally {
            RawMonths::remove($rendered);
        }
    }

    private function legacyDifference(string $outDir, ?ArchiveHeader $header, ?ArchiveMetadata $metadata, string $messagesPath): ?RenderDifference
    {
        $temp = tempnam(sys_get_temp_dir(), 'slack-render-');

        throw_if($temp === false, RuntimeException::class, 'Unable to create a temporary file for the render');
        throw_if($header === null, LogicException::class, 'A single raw.md is only checked once its header is read');

        try {
            $this->render($header, $metadata, $messagesPath, LegacyRawFile::openAt($temp, 0, $header->toMarkdown()));

            return RenderDifference::between('raw.md', RawMonths::legacyPathIn($outDir), $temp);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * An archive that holds no messages has no month files, and so no
     * header to read: there is nothing to render.
     *
     * @return array{messages: int, threads: int, replies: int, first_day: ?string, last_day: ?string}
     */
    private function renderMonths(?ArchiveHeader $header, ?ArchiveMetadata $metadata, string $messagesPath, string $dir): array
    {
        if ($header === null) {
            return self::EMPTY_SUMMARY;
        }

        return $this->render($header, $metadata, $messagesPath, MonthSplitter::fresh($dir, $header));
    }

    /**
     * The header comes from the single raw.md while the directory is on
     * it, and from the newest month file once it is on raw/. Only an
     * archive that holds messages needs one on raw/.
     *
     * @return array{0: ?ArchiveHeader, 1: ?ArchiveMetadata, 2: string}
     */
    private function sources(string $outDir): array
    {
        $messagesPath = ArchiveRequest::messagesPathIn($outDir);

        throw_if(
            is_file(ArchiveRequest::checkpointPathIn($outDir)),
            RuntimeException::class,
            "The directory {$outDir} holds an interrupted archive run. Finish it with `slack-cli archive --resume` before rendering it",
        );

        throw_unless(
            is_file($messagesPath),
            RuntimeException::class,
            "Unable to find messages.jsonl in {$outDir}. archive:render renders raw/ from it",
        );

        $headerPath = $this->headerPath($outDir);
        $header = $headerPath === null ? null : ArchiveHeader::read($headerPath);

        throw_if(
            $header === null && (RawMonths::isLegacy($outDir) || Jsonl::read($messagesPath)->valid()),
            RuntimeException::class,
            'Unable to read the archive header at the top of '.($headerPath ?? RawMonths::dirIn($outDir).'/<newest month>.md').'. archive:render takes the channel, workspace and timezone from it',
        );

        return [$header, ArchiveMetadata::load($outDir), $messagesPath];
    }

    private function headerPath(string $outDir): ?string
    {
        if (RawMonths::isLegacy($outDir)) {
            return RawMonths::legacyPathIn($outDir);
        }

        $dir = RawMonths::dirIn($outDir);
        $newest = RawMonths::newestIn($dir);

        return $newest === null ? null : RawMonths::pathOf($dir, $newest);
    }

    /**
     * Stream every message into the sink. A render that fails part way
     * leaves nothing behind for a later run to mistake for a whole one.
     *
     * @return array{messages: int, threads: int, replies: int, first_day: ?string, last_day: ?string}
     */
    private function render(ArchiveHeader $header, ?ArchiveMetadata $metadata, string $messagesPath, RawSink $sink): array
    {
        $renderer = new MarkdownRenderer(new SlackMarkup($this->names), $this->names, new DateTimeZone($header->timezone));

        $summary = self::EMPTY_SUMMARY;

        try {
            foreach ($this->threads($messagesPath, $metadata ?? new ArchiveMetadata('', '')) as [$message, $replies]) {
                $sink->write($renderer->message($message, $replies));

                $day = $renderer->day((string) Arr::get($message, 'ts', '0'));

                $summary['messages']++;
                $summary['replies'] += count($replies);
                $summary['threads'] += $replies === [] ? 0 : 1;
                $summary['first_day'] ??= $day;
                $summary['last_day'] = $day;
            }

            $sink->close();
        } catch (Throwable $e) {
            $sink->abort();

            throw $e;
        }

        return $summary;
    }

    /**
     * messages.jsonl holds each thread's replies right after their parent.
     * They are gathered back under it one thread at a time, unless the run
     * that archived the parent skipped threads: a reply also sent to the
     * channel then stands on its own, even right after its parent.
     *
     * @return Generator<int, array{0: array<string, mixed>, 1: list<array<string, mixed>>}>
     */
    private function threads(string $messagesPath, ArchiveMetadata $metadata): Generator
    {
        $parent = null;
        $replies = [];

        foreach (Jsonl::read($messagesPath) as $message) {
            if ($parent !== null
                && $this->isReplyTo($message, $parent)
                && $metadata->nestsRepliesAt((string) Arr::get($parent, 'ts', '0'))) {
                $replies[] = $message;

                continue;
            }

            if ($parent !== null) {
                yield [$parent, $replies];
            }

            $parent = $message;
            $replies = [];
        }

        if ($parent !== null) {
            yield [$parent, $replies];
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $parent
     */
    private function isReplyTo(array $message, array $parent): bool
    {
        $threadTs = Arr::get($message, 'thread_ts');

        return is_string($threadTs)
            && $threadTs !== Arr::get($message, 'ts')
            && $threadTs === Arr::get($parent, 'ts');
    }
}
