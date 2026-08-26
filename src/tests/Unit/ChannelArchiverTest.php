<?php

use App\Archive\ArchiveCheckpoint;
use App\Archive\ArchiveRequest;
use App\Archive\ChannelArchiver;
use App\Archive\Jsonl;
use Tests\Support\FakeSlackClient;

function archiveRequest(string $dir, array $overrides = []): ArchiveRequest
{
    return new ArchiveRequest(
        channelId: $overrides['channelId'] ?? 'C47JM9E9K',
        outDir: $dir,
        oldest: $overrides['oldest'] ?? null,
        latest: $overrides['latest'] ?? null,
        includeThreads: $overrides['includeThreads'] ?? true,
        resume: $overrides['resume'] ?? false,
        sinceLast: $overrides['sinceLast'] ?? false,
    );
}

it('writes both files, ordered oldest first, with replies under their parent', function () {
    $dir = archiveDir();

    $summary = (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir));

    $order = array_column(iterator_to_array(Jsonl::read($dir.'/messages.jsonl')), 'ts');

    expect($order)->toBe([TS_0914, TS_0920, TS_0930, TS_NEXT_DAY])
        ->and($summary->messages)->toBe(3)
        ->and($summary->threads)->toBe(1)
        ->and($summary->replies)->toBe(1);

    expect(file_get_contents($dir.'/raw.md'))->toBe(
        "# #eng-leadership — Dump completo (2026-08-01 a 2026-08-02)\n\n"
        ."Workspace: publica.la. Canal privado, 12 miembros.\n"
        ."Horarios en UTC .\n"
        ."\n## 2026-08-01\n"
        ."\n**09:14 Franco Gilio:** ¿arrancamos?\n"
        ."  > **09:20 Ana Pérez:** dale\n"
        ."\n**09:30 Ana Pérez:** listo\n"
        ."\n## 2026-08-02\n"
        ."\n**10:05 Franco Gilio:** seguimos mañana\n"
    );
});

it('writes a reply broadcast to the channel only once', function () {
    $dir = archiveDir();

    $client = new FakeSlackClient(
        pages: [[
            ['ts' => TS_0920, 'user' => 'U02', 'text' => 'dale', 'thread_ts' => TS_0914, 'subtype' => 'thread_broadcast'],
            ['ts' => TS_0914, 'user' => 'U01', 'text' => '¿arrancamos?', 'thread_ts' => TS_0914, 'reply_count' => 1],
        ]],
        threads: [TS_0914 => [
            ['ts' => TS_0914, 'user' => 'U01', 'text' => '¿arrancamos?'],
            ['ts' => TS_0920, 'user' => 'U02', 'text' => 'dale'],
        ]],
        users: ['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'],
    );

    (new ChannelArchiver($client))->archive(archiveRequest($dir));

    $order = array_column(iterator_to_array(Jsonl::read($dir.'/messages.jsonl')), 'ts');

    expect($order)->toBe([TS_0914, TS_0920])
        ->and(substr_count((string) file_get_contents($dir.'/raw.md'), 'dale'))->toBe(1);
});

it('keeps a broadcast reply in the channel stream when threads are skipped', function () {
    $dir = archiveDir();

    $client = new FakeSlackClient(
        pages: [[['ts' => TS_0920, 'user' => 'U02', 'text' => 'dale', 'thread_ts' => TS_0914, 'subtype' => 'thread_broadcast']]],
        users: ['U02' => 'Ana Pérez'],
    );

    $summary = (new ChannelArchiver($client))->archive(archiveRequest($dir, ['includeThreads' => false]));

    expect($summary->messages)->toBe(1);
});

it('clears its checkpoint and scratch directory when it finishes', function () {
    $dir = archiveDir();

    (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir));

    expect(file_exists($dir.'/.archive-checkpoint.json'))->toBeFalse()
        ->and(is_dir($dir.'/.archive-tmp'))->toBeFalse();
});

it('skips thread replies when asked to', function () {
    $dir = archiveDir();

    $summary = (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir, ['includeThreads' => false]));

    expect($summary->replies)->toBe(0)
        ->and(file_get_contents($dir.'/raw.md'))->not->toContain('dale');
});

it('refuses to archive over an existing archive', function () {
    $dir = archiveDir();
    $archiver = new ChannelArchiver(fixtureClient());
    $archiver->archive(archiveRequest($dir));

    expect(fn () => $archiver->archive(archiveRequest($dir)))
        ->toThrow(RuntimeException::class, 'already holds an archive');
});

it('refuses to start over an interrupted run', function () {
    $dir = archiveDir();

    (new ArchiveCheckpoint($dir.'/.archive-checkpoint.json', 'C47JM9E9K', null, null, true, false))->save();

    expect(fn () => (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir)))
        ->toThrow(RuntimeException::class, 'Pass --resume');
});

it('refuses to resume a checkpoint from a different window', function () {
    $dir = archiveDir();

    (new ArchiveCheckpoint($dir.'/.archive-checkpoint.json', 'C47JM9E9K', '1.0', null, true, false))->save();

    expect(fn () => (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir, ['resume' => true])))
        ->toThrow(RuntimeException::class, 'different channel or date window');
});

it('refuses to resume when there is nothing to resume', function () {
    expect(fn () => (new ChannelArchiver(fixtureClient()))->archive(archiveRequest(archiveDir(), ['resume' => true])))
        ->toThrow(RuntimeException::class, 'no archive run to resume');
});

it('resumes a run that died before rendering', function () {
    $dir = archiveDir();
    $client = fixtureClient();

    // A run that reached the fetch phase and stopped: the checkpoint stands,
    // the output files are still empty.
    $checkpoint = new ArchiveCheckpoint($dir.'/.archive-checkpoint.json', 'C47JM9E9K', null, null, true, false);
    $checkpoint->save();

    $summary = (new ChannelArchiver($client))->archive(archiveRequest($dir, ['resume' => true]));

    expect($summary->messages)->toBe(3)
        ->and(array_column(iterator_to_array(Jsonl::read($dir.'/messages.jsonl')), 'ts'))
        ->toBe([TS_0914, TS_0920, TS_0930, TS_NEXT_DAY]);
});

it('cuts back a half-written render instead of duplicating it', function () {
    $dir = archiveDir();

    file_put_contents($dir.'/messages.jsonl', Jsonl::encode(['ts' => TS_0914])."\n{\"broken\"");
    file_put_contents($dir.'/raw.md', "# leftovers\nhalf a line");

    $checkpoint = new ArchiveCheckpoint($dir.'/.archive-checkpoint.json', 'C47JM9E9K', null, null, true, false);
    $checkpoint->phase = ArchiveCheckpoint::PHASE_HISTORY;
    $checkpoint->save();

    (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir, ['resume' => true]));

    expect(array_column(iterator_to_array(Jsonl::read($dir.'/messages.jsonl')), 'ts'))
        ->toBe([TS_0914, TS_0920, TS_0930, TS_NEXT_DAY])
        ->and(file_get_contents($dir.'/raw.md'))->toStartWith('# #eng-leadership');
});

it('extends an archive forward without repeating what it already holds', function () {
    $dir = archiveDir();

    (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir));

    $laterClient = new FakeSlackClient(
        pages: [
            [
                ['ts' => TS_LATER, 'user' => 'U02', 'text' => 'una más'],
                // conversations.history is inclusive, so the boundary message
                // comes back a second time and must not be written twice.
                ['ts' => TS_NEXT_DAY, 'user' => 'U01', 'text' => 'seguimos mañana'],
            ],
        ],
        users: ['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'],
    );

    $summary = (new ChannelArchiver($laterClient))->archive(archiveRequest($dir, ['sinceLast' => true]));

    expect($summary->messages)->toBe(1)
        ->and($summary->skipped)->toBe(1)
        ->and(array_column(iterator_to_array(Jsonl::read($dir.'/messages.jsonl')), 'ts'))
        ->toBe([TS_0914, TS_0920, TS_0930, TS_NEXT_DAY, TS_LATER]);

    $markdown = file_get_contents($dir.'/raw.md');

    expect(substr_count($markdown, 'seguimos mañana'))->toBe(1)
        ->and(substr_count($markdown, '## 2026-08-02'))->toBe(1)
        ->and($markdown)->toEndWith("**11:05 Ana Pérez:** una más\n");
});

it('moves the header range forward on an incremental run', function () {
    $dir = archiveDir();

    (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir));

    $laterClient = new FakeSlackClient(
        pages: [[['ts' => '1785751500.000600', 'user' => 'U01', 'text' => 'tercer día']]],
        users: ['U01' => 'Franco Gilio'],
    );

    (new ChannelArchiver($laterClient))->archive(archiveRequest($dir, ['sinceLast' => true]));

    expect(file_get_contents($dir.'/raw.md'))
        ->toStartWith("# #eng-leadership — Dump completo (2026-08-01 a 2026-08-03)\n");
});

it('treats an incremental run on an empty directory as a first archive', function () {
    $dir = archiveDir();

    $summary = (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir, ['sinceLast' => true]));

    expect($summary->messages)->toBe(3)
        ->and(file_get_contents($dir.'/raw.md'))->toStartWith('# #eng-leadership');
});

it('honours an inclusive date window', function () {
    $dir = archiveDir();

    $summary = (new ChannelArchiver(fixtureClient()))->archive(archiveRequest($dir, [
        'oldest' => '1785542400.000000',
        'latest' => '1785628799.999999',
    ]));

    expect($summary->messages)->toBe(2)
        ->and($summary->firstDay)->toBe('2026-08-01')
        ->and($summary->lastDay)->toBe('2026-08-01');
});
