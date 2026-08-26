<?php

use App\Archive\ArchiveCheckpoint;
use App\Archive\BatchEntry;
use App\Archive\BatchResult;
use App\Archive\BatchRunner;
use App\Archive\SlackEntryArchiver;
use Tests\Support\FakeEntryArchiver;
use Tests\Support\FakeSlackClient;

function batchEntry(string $target, ?string $out = null, array $overrides = []): BatchEntry
{
    return new BatchEntry(
        target: $target,
        outDir: $out ?? sys_get_temp_dir().'/slack-cli-batch-'.bin2hex(random_bytes(6)),
        after: $overrides['after'] ?? null,
        before: $overrides['before'] ?? null,
        noThreads: $overrides['noThreads'] ?? false,
    );
}

it('archives every entry in manifest order', function () {
    $archiver = new FakeEntryArchiver(['@gparra' => 12, 'engineering-team' => 4]);

    $results = (new BatchRunner($archiver))->run([batchEntry('@gparra'), batchEntry('engineering-team')]);

    expect($archiver->archived)->toBe(['@gparra', 'engineering-team'])
        ->and(array_map(fn (BatchResult $result) => $result->status(), $results))
        ->toBe([BatchResult::STATUS_ARCHIVED, BatchResult::STATUS_ARCHIVED]);
});

it('keeps going after an entry fails', function () {
    $archiver = new FakeEntryArchiver([
        '@gparra' => 12,
        'missing' => 'Channel not found. Check the name or ID',
        'engineering-team' => 4,
    ]);

    $results = (new BatchRunner($archiver))->run([
        batchEntry('@gparra'),
        batchEntry('missing'),
        batchEntry('engineering-team'),
    ]);

    expect($archiver->archived)->toBe(['@gparra', 'missing', 'engineering-team'])
        ->and($results[1]->succeeded())->toBeFalse()
        ->and($results[1]->error)->toBe('Channel not found. Check the name or ID')
        ->and($results[1]->outcome())->toBe('FAILED: Channel not found. Check the name or ID')
        ->and($results[2]->succeeded())->toBeTrue();
});

it('counts the entries that failed', function () {
    $results = (new BatchRunner(new FakeEntryArchiver([
        'a' => 1,
        'b' => 'boom',
        'c' => 'bang',
    ])))->run([batchEntry('a'), batchEntry('b'), batchEntry('c')]);

    expect(BatchRunner::failureCount($results))->toBe(2);
});

it('counts nothing as failed when every entry works', function () {
    $results = (new BatchRunner(new FakeEntryArchiver(['a' => 1])))->run([batchEntry('a')]);

    expect(BatchRunner::failureCount($results))->toBe(0);
});

it('reports an entry with nothing new as up to date', function () {
    $results = (new BatchRunner(new FakeEntryArchiver(['a' => 0])))->run([batchEntry('a')]);

    expect($results[0]->status())->toBe(BatchResult::STATUS_UP_TO_DATE)
        ->and($results[0]->outcome())->toBe('up to date')
        ->and($results[0]->succeeded())->toBeTrue();
});

it('reports what was appended', function () {
    $results = (new BatchRunner(new FakeEntryArchiver(['a' => 12])))->run([batchEntry('a')]);

    expect($results[0]->outcome())->toBe('12 messages, 2 replies appended');
});

it('announces each entry before and after it runs', function () {
    $seen = [];

    (new BatchRunner(new FakeEntryArchiver(['a' => 1, 'b' => 'boom'])))->run(
        [batchEntry('a'), batchEntry('b')],
        onStart: function (BatchEntry $entry, int $position, int $total) use (&$seen) {
            $seen[] = "start {$position}/{$total} {$entry->target}";
        },
        onFinish: function (BatchResult $result, int $position, int $total) use (&$seen) {
            $seen[] = "finish {$position}/{$total} {$result->status()}";
        },
    );

    expect($seen)->toBe([
        'start 1/2 a',
        'finish 1/2 archived',
        'start 2/2 b',
        'finish 2/2 failed',
    ]);
});

it('passes the progress reporter down to each entry', function () {
    $lines = [];

    (new BatchRunner(new FakeEntryArchiver(['a' => 1, 'b' => 1])))->run(
        [batchEntry('a'), batchEntry('b')],
        progress: function (string $line) use (&$lines) {
            $lines[] = $line;
        },
    );

    expect($lines)->toBe(['working on a', 'working on b']);
});

it('carries an entry into the machine-readable summary', function () {
    $entry = batchEntry('@gparra', '/tmp/gonza');

    $results = (new BatchRunner(new FakeEntryArchiver(['@gparra' => 12])))->run([$entry]);

    expect($results[0]->toArray())->toBe([
        'target' => '@gparra',
        'out' => '/tmp/gonza',
        'status' => 'archived',
        'channel' => '@gparra',
        'channel_id' => 'CGPARRA',
        'messages' => 12,
        'replies' => 2,
        'threads' => 1,
        'first_day' => '2026-08-01',
        'last_day' => '2026-08-02',
        'messages_path' => '/tmp/gonza/messages.jsonl',
        'markdown_path' => '/tmp/gonza/raw.md',
        'error' => null,
    ]);
});

it('zeroes the counts of a failed entry in the summary', function () {
    $results = (new BatchRunner(new FakeEntryArchiver(['a' => 'boom'])))->run([batchEntry('a', '/tmp/a')]);

    expect($results[0]->toArray())->toMatchArray([
        'status' => 'failed',
        'messages' => 0,
        'replies' => 0,
        'threads' => 0,
        'channel_id' => null,
        'error' => 'boom',
    ]);
});

it('runs an entry through the real archiver and leaves both files behind', function () {
    $dir = archiveDir();

    $results = (new BatchRunner(new SlackEntryArchiver(fixtureClient())))->run([batchEntry('@gparra', $dir)]);

    expect($results[0]->succeeded())->toBeTrue()
        ->and($results[0]->summary?->messages)->toBe(3)
        ->and(is_file($dir.'/messages.jsonl'))->toBeTrue()
        ->and(is_file($dir.'/raw.md'))->toBeTrue();
});

it('records the failure of a target it cannot resolve', function () {
    $results = (new BatchRunner(new SlackEntryArchiver(fixtureClient())))->run([batchEntry('missing')]);

    expect($results[0]->succeeded())->toBeFalse()
        ->and($results[0]->error)->toBe('Channel not found. Check the name or ID');
});

it('lets since-last win over an entry that also carries an after', function () {
    $dir = archiveDir();

    (new BatchRunner(new SlackEntryArchiver(fixtureClient())))->run([batchEntry('eng', $dir)]);

    $later = new FakeSlackClient(
        pages: [[
            ['ts' => TS_LATER, 'user' => 'U02', 'text' => 'una más'],
            ['ts' => TS_NEXT_DAY, 'user' => 'U01', 'text' => 'seguimos mañana'],
        ]],
        users: ['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'],
    );

    // The entry's own floor sits well before what the archive already holds,
    // so the refresh must fetch from the newest archived message instead.
    $results = (new BatchRunner(new SlackEntryArchiver($later, sinceLast: true)))
        ->run([batchEntry('eng', $dir, ['after' => '2026-08-01'])]);

    expect($results[0]->summary?->messages)->toBe(1)
        ->and($results[0]->summary?->skipped)->toBe(1);
});

it('honours an entry date window when since-last is off', function () {
    $dir = archiveDir();

    $results = (new BatchRunner(new SlackEntryArchiver(fixtureClient())))
        ->run([batchEntry('eng', $dir, ['after' => '2026-08-01', 'before' => '2026-08-01'])]);

    expect($results[0]->summary?->messages)->toBe(2)
        ->and($results[0]->summary?->lastDay)->toBe('2026-08-01');
});

it('skips thread replies for an entry that asks it to', function () {
    $dir = archiveDir();

    $results = (new BatchRunner(new SlackEntryArchiver(fixtureClient())))
        ->run([batchEntry('eng', $dir, ['noThreads' => true])]);

    expect($results[0]->summary?->replies)->toBe(0);
});

it('resumes an entry an earlier batch left interrupted', function () {
    $dir = archiveDir();

    (new ArchiveCheckpoint($dir.'/.archive-checkpoint.json', 'C47JM9E9K', null, null, true, false))->save();

    $results = (new BatchRunner(new SlackEntryArchiver(fixtureClient())))->run([batchEntry('eng', $dir)]);

    expect($results[0]->succeeded())->toBeTrue()
        ->and($results[0]->summary?->messages)->toBe(3);
});
