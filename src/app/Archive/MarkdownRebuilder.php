<?php

namespace App\Archive;

use DateTimeZone;
use Generator;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * Rebuilds an archive's raw.md from its messages.jsonl.
 *
 * Nothing is fetched. The messages come from messages.jsonl, and the
 * channel, workspace, date range and timezone from the header the
 * archive run wrote, so the result is what `archive` would write
 * for the same messages under the renderer as it stands today.
 */
final class MarkdownRebuilder
{
    public function __construct(private readonly NameResolver $names) {}

    public function rebuild(string $outDir): ArchiveSummary
    {
        [$header, $metadata, $messagesPath, $markdownPath] = $this->sources($outDir);
        $temp = $markdownPath.'.part';

        try {
            $summary = $this->write($header, $metadata, $messagesPath, $temp);
        } catch (Throwable $e) {
            @unlink($temp);

            throw $e;
        }

        throw_unless(
            rename($temp, $markdownPath),
            RuntimeException::class,
            "Unable to move the rebuilt {$temp} into place at {$markdownPath}",
        );

        return new ArchiveSummary(
            channelId: $metadata->channelId ?? $header->channelLabel,
            channelLabel: $header->channelLabel,
            messages: $summary['messages'],
            threads: $summary['threads'],
            replies: $summary['replies'],
            skipped: 0,
            firstDay: $summary['first_day'],
            lastDay: $summary['last_day'],
            messagesPath: $messagesPath,
            markdownPath: $markdownPath,
        );
    }

    /**
     * The first raw.md line a render would change, or null when raw.md
     * matches messages.jsonl, as it must after both files are redacted.
     * The render goes to a temporary file, so the archive is untouched.
     */
    public function firstStaleLine(string $outDir): ?int
    {
        [$header, $metadata, $messagesPath, $markdownPath] = $this->sources($outDir);
        $temp = tempnam(sys_get_temp_dir(), 'slack-render-');

        throw_if($temp === false, RuntimeException::class, 'Unable to create a temporary file for the render');

        try {
            $this->write($header, $metadata, $messagesPath, $temp);

            return $this->firstDifferentLine($markdownPath, $temp);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return array{0: ArchiveHeader, 1: ?ArchiveMetadata, 2: string, 3: string}
     */
    private function sources(string $outDir): array
    {
        $messagesPath = ArchiveRequest::messagesPathIn($outDir);
        $markdownPath = ArchiveRequest::markdownPathIn($outDir);

        throw_if(
            is_file(ArchiveRequest::checkpointPathIn($outDir)),
            RuntimeException::class,
            "The directory {$outDir} holds an interrupted archive run. Finish it with `slack-cli archive --resume` before rendering it",
        );

        throw_unless(
            is_file($messagesPath),
            RuntimeException::class,
            "Unable to find messages.jsonl in {$outDir}. archive:render rebuilds raw.md from it",
        );

        $header = ArchiveHeader::read($markdownPath);

        throw_if(
            $header === null,
            RuntimeException::class,
            "Unable to read the archive header at the top of {$markdownPath}. archive:render takes the channel, workspace and timezone from it",
        );

        return [$header, ArchiveMetadata::load($outDir), $messagesPath, $markdownPath];
    }

    private function firstDifferentLine(string $current, string $rendered): ?int
    {
        $left = fopen($current, 'rb');
        $right = fopen($rendered, 'rb');

        throw_if($left === false || $right === false, RuntimeException::class, "Unable to read {$current} to compare it with the render");

        try {
            for ($line = 1; ; $line++) {
                $currentLine = fgets($left);
                $renderedLine = fgets($right);

                if ($currentLine === false && $renderedLine === false) {
                    return null;
                }

                if ($currentLine !== $renderedLine) {
                    return $line;
                }
            }
        } finally {
            fclose($left);
            fclose($right);
        }
    }

    /**
     * @return array{messages: int, threads: int, replies: int, first_day: ?string, last_day: ?string}
     */
    private function write(ArchiveHeader $header, ?ArchiveMetadata $metadata, string $messagesPath, string $temp): array
    {
        $renderer = new MarkdownRenderer(new SlackMarkup($this->names), $this->names, new DateTimeZone($header->timezone));

        $out = fopen($temp, 'wb');

        throw_if($out === false, RuntimeException::class, "Unable to write the archive file at {$temp}");

        $summary = ['messages' => 0, 'threads' => 0, 'replies' => 0, 'first_day' => null, 'last_day' => null];

        try {
            $this->append($out, $header->toMarkdown(), $temp);

            foreach ($this->threads($messagesPath, $metadata ?? new ArchiveMetadata('', '')) as [$message, $replies]) {
                $this->append($out, $renderer->message($message, $replies), $temp);

                $day = $renderer->day((string) Arr::get($message, 'ts', '0'));

                $summary['messages']++;
                $summary['replies'] += count($replies);
                $summary['threads'] += $replies === [] ? 0 : 1;
                $summary['first_day'] ??= $day;
                $summary['last_day'] = $day;
            }

            throw_unless(fflush($out), RuntimeException::class, "Unable to finish writing the render at {$temp}. Check the free disk space");
        } finally {
            fclose($out);
        }

        return $summary;
    }

    /**
     * A full disk makes fwrite() write less than it was given. A cut render
     * must never be compared with raw.md or moved into its place.
     *
     * @param  resource  $out
     */
    private function append($out, string $text, string $path): void
    {
        throw_if(
            fwrite($out, $text) !== strlen($text),
            RuntimeException::class,
            "Unable to write the render to {$path}. Check the free disk space",
        );
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
