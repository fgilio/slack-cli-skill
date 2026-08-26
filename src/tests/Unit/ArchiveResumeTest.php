<?php

use App\Archive\ArchiveCheckpoint;
use App\Archive\ChannelArchiver;
use App\Archive\Jsonl;

/**
 * What a single uninterrupted run of the same fixture writes.
 *
 * @return array{0: list<string>, 1: string}
 */
function freshRunOutput(): array
{
    static $output = null;

    if ($output === null) {
        $dir = archiveDir();
        (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir));
        $output = [archivedTimestamps($dir), (string) file_get_contents($dir.'/raw.md')];
    }

    return $output;
}

/**
 * Plant the on-disk state of a run that fetched pages and then died,
 * without going through the archiver, so a kill can be placed exactly.
 *
 * @param  list<list<array<string, mixed>>>  $pages
 */
function plantInterruptedRun(string $dir, array $pages, string $phase, ?string $cursor, ?int $pageCount = null): void
{
    mkdir($dir.'/.archive-tmp/pages', 0755, true);
    mkdir($dir.'/.archive-tmp/threads', 0755, true);

    foreach ($pages as $index => $page) {
        Jsonl::writeAtomic(sprintf('%s/.archive-tmp/pages/page-%06d.jsonl', $dir, $index), array_reverse($page));
    }

    $checkpoint = new ArchiveCheckpoint($dir.'/.archive-checkpoint.json', 'C47JM9E9K', null, null, true, false);
    $checkpoint->phase = $phase;
    $checkpoint->cursor = $cursor;
    $checkpoint->pageCount = $pageCount ?? count($pages);
    $checkpoint->save();
}

/**
 * The planted page files, newest slice first, the way the fixture is dealt.
 *
 * @return list<string>
 */
function plantedPagePaths(string $dir): array
{
    return array_map(
        fn (int $index) => sprintf('%s/.archive-tmp/pages/page-%06d.jsonl', $dir, $index),
        range(0, count(pagedFixture()) - 1),
    );
}

/**
 * The ordered stream the phases before `threads` would have left behind.
 */
function plantOrderedHistory(string $dir): void
{
    Jsonl::concat(array_reverse(plantedPagePaths($dir)), $dir.'/.archive-tmp/history.jsonl');
}

it('deals the fixture out newest page first', function () {
    [$fresh] = freshRunOutput();

    $sorted = $fresh;
    sort($sorted);

    expect($fresh)->toHaveCount(10)->toBe($sorted);
});

it('resumes a run that died mid-history in chronological order', function () {
    $dir = archiveDir();

    expect(fn () => (new ChannelArchiver(pagedClient(crashAfterPages: 3)))->archive(resumeRequest($dir)))
        ->toThrow(RuntimeException::class, 'Connection reset');

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true));

    [$fresh, $freshMarkdown] = freshRunOutput();

    expect(archivedTimestamps($dir))->toBe($fresh)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($freshMarkdown);
});

it('picks up at the page the interrupted run was about to fetch', function () {
    $dir = archiveDir();

    try {
        (new ChannelArchiver(pagedClient(crashAfterPages: 3)))->archive(resumeRequest($dir));
    } catch (RuntimeException) {
        // The kill is the point of the fixture.
    }

    $client = pagedClient();
    (new ChannelArchiver($client))->archive(resumeRequest($dir, resume: true));

    expect($client->cursorsRequested)->toBe(['3']);
});

/**
 * The bug this guards: a run killed between its last page and the phase
 * flip leaves a checkpoint that still says "history" with no cursor left.
 * Handing that null back to conversations.history means "start from the
 * newest message", so the channel is walked a second time and stacked on
 * top of the pages already on disk.
 */
it('does not refetch the channel when the cursor is already spent', function () {
    $dir = archiveDir();

    plantInterruptedRun($dir, pagedFixture(), ArchiveCheckpoint::PHASE_HISTORY, cursor: null);

    $client = pagedClient();
    (new ChannelArchiver($client))->archive(resumeRequest($dir, resume: true));

    [$fresh, $freshMarkdown] = freshRunOutput();

    expect($client->cursorsRequested)->toBe([])
        ->and(archivedTimestamps($dir))->toBe($fresh)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($freshMarkdown);
});

it('never stores a spent cursor while it still says it is fetching', function () {
    $dir = archiveDir();
    $seen = [];

    // Every checkpoint the run writes, captured as it lands on disk.
    $watch = function () use ($dir, &$seen) {
        $stored = ArchiveCheckpoint::load($dir.'/.archive-checkpoint.json');

        if ($stored !== null) {
            $seen[] = [$stored->phase, $stored->cursor, $stored->pageCount];
        }
    };

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir), function () use ($watch) {
        $watch();
    });

    $ambiguous = array_filter(
        $seen,
        fn (array $state) => $state[0] === ArchiveCheckpoint::PHASE_HISTORY && $state[1] === null && $state[2] > 0,
    );

    expect($seen)->not->toBeEmpty()
        ->and($ambiguous)->toBe([]);
});

it('resumes a run killed part way through the ordering pass', function () {
    $dir = archiveDir();

    plantInterruptedRun($dir, pagedFixture(), ArchiveCheckpoint::PHASE_ORDER, cursor: null);
    file_put_contents($dir.'/.archive-tmp/history.jsonl.part', '{"ts":"1785575640.000100","half');

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true));

    [$fresh, $freshMarkdown] = freshRunOutput();

    expect(archivedTimestamps($dir))->toBe($fresh)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($freshMarkdown);
});

it('resumes a run killed part way through the thread pass', function () {
    $dir = archiveDir();

    plantInterruptedRun($dir, pagedFixture(), ArchiveCheckpoint::PHASE_ORDER, cursor: null);
    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true));

    $again = archiveDir();
    plantInterruptedRun($again, pagedFixture(), ArchiveCheckpoint::PHASE_THREADS, cursor: null);
    plantOrderedHistory($again);

    $checkpoint = ArchiveCheckpoint::load($again.'/.archive-checkpoint.json');
    $checkpoint->threadLine = 4;
    $checkpoint->save();

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($again, resume: true));

    [$fresh, $freshMarkdown] = freshRunOutput();

    expect(archivedTimestamps($again))->toBe($fresh)
        ->and(file_get_contents($again.'/raw.md'))->toBe($freshMarkdown);
});

it('resumes a run killed part way through rendering', function () {
    $dir = archiveDir();

    plantInterruptedRun($dir, pagedFixture(), ArchiveCheckpoint::PHASE_THREADS, cursor: null);
    plantOrderedHistory($dir);

    $checkpoint = ArchiveCheckpoint::load($dir.'/.archive-checkpoint.json');
    $checkpoint->phase = ArchiveCheckpoint::PHASE_RENDER;
    $checkpoint->save();

    file_put_contents($dir.'/messages.jsonl', '{"ts":"1785575640.000100"}'."\n".'{"tor');
    file_put_contents($dir.'/raw.md', "# sobras\nmedia línea");

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true));

    [$fresh, $freshMarkdown] = freshRunOutput();

    expect(archivedTimestamps($dir))->toBe($fresh)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($freshMarkdown);
});

it('refuses to order a run whose pages went missing', function () {
    $dir = archiveDir();

    plantInterruptedRun($dir, pagedFixture(), ArchiveCheckpoint::PHASE_ORDER, cursor: null);
    unlink($dir.'/.archive-tmp/pages/page-000002.jsonl');

    expect(fn () => (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true)))
        ->toThrow(RuntimeException::class, 'is missing page 2 of 5');
});

it('survives being killed and resumed twice', function () {
    $dir = archiveDir();

    try {
        (new ChannelArchiver(pagedClient(crashAfterPages: 2)))->archive(resumeRequest($dir));
    } catch (RuntimeException) {
        // First kill.
    }

    try {
        (new ChannelArchiver(pagedClient(crashAfterPages: 1)))->archive(resumeRequest($dir, resume: true));
    } catch (RuntimeException) {
        // Second kill, part way through the resumed run.
    }

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true));

    [$fresh, $freshMarkdown] = freshRunOutput();

    expect(archivedTimestamps($dir))->toBe($fresh)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($freshMarkdown);
});

it('leaves nothing behind once a resumed run finishes', function () {
    $dir = archiveDir();

    try {
        (new ChannelArchiver(pagedClient(crashAfterPages: 3)))->archive(resumeRequest($dir));
    } catch (RuntimeException) {
        // The kill is the point of the fixture.
    }

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true));

    expect(file_exists($dir.'/.archive-checkpoint.json'))->toBeFalse()
        ->and(is_dir($dir.'/.archive-tmp'))->toBeFalse()
        ->and(is_file($dir.'/.archive-meta.json'))->toBeTrue();
});

it('refuses to render a stream that runs backwards', function () {
    $dir = archiveDir();

    plantInterruptedRun($dir, pagedFixture(), ArchiveCheckpoint::PHASE_THREADS, cursor: null);

    // Pages joined newest slice first, which is what a floored run used to
    // produce: each block ordered, the blocks themselves not.
    Jsonl::concat(plantedPagePaths($dir), $dir.'/.archive-tmp/history.jsonl');

    expect(fn () => (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true)))
        ->toThrow(RuntimeException::class, 'came out in the wrong order');
});
