<?php

use App\Archive\BatchEntry;
use App\Archive\BatchResult;
use App\Archive\ChannelArchiver;
use App\Archive\Jsonl;
use App\Archive\MarkdownRebuilder;
use Tests\Support\FakeNameResolver;
use Tests\Support\FakeSlackClient;

const TS_LATE_REPLY = '1785668760.000900';

/**
 * The fixture channel, as Slack answers it: the reply carries its thread_ts.
 */
function threadedClient(array $replies = [], int $replyCount = 1, ?string $latestReply = null, array $extraPages = []): FakeSlackClient
{
    $parent = ['ts' => TS_0914, 'user' => 'U01', 'text' => '¿arrancamos?', 'thread_ts' => TS_0914, 'reply_count' => $replyCount]
        + ($latestReply === null ? [] : ['latest_reply' => $latestReply]);

    return new FakeSlackClient(
        pages: [
            ...$extraPages,
            [['ts' => TS_NEXT_DAY, 'user' => 'U01', 'text' => 'seguimos mañana']],
            [['ts' => TS_0930, 'user' => 'U02', 'text' => 'listo'], $parent],
        ],
        threads: [
            TS_0914 => [
                ['ts' => TS_0914, 'user' => 'U01', 'text' => '¿arrancamos?', 'thread_ts' => TS_0914],
                ['ts' => TS_0920, 'user' => 'U02', 'text' => 'dale', 'thread_ts' => TS_0914],
                ...$replies,
            ],
        ],
        users: ['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'],
    );
}

function lateReply(): array
{
    return ['ts' => TS_LATE_REPLY, 'user' => 'U01', 'text' => 'ya está', 'thread_ts' => TS_0914];
}

it('folds a reply posted later to an archived thread under its parent', function () {
    $dir = archiveDir();

    (new ChannelArchiver(threadedClient()))->archive(archiveRequest($dir));

    $later = threadedClient([lateReply()], replyCount: 2, latestReply: TS_LATE_REPLY);
    $summary = (new ChannelArchiver($later))->archive(archiveRequest($dir, ['sinceLast' => true]));

    expect($summary->messages)->toBe(0)
        ->and($summary->lateThreads)->toBe(1)
        ->and($summary->lateReplies)->toBe(1)
        ->and(array_column(iterator_to_array(Jsonl::read($dir.'/messages.jsonl')), 'ts'))
        ->toBe([TS_0914, TS_0920, TS_LATE_REPLY, TS_0930, TS_NEXT_DAY])
        ->and(rawMonths($dir)['2026-08'])->toContain("  > **09:20 Ana Pérez:** dale\n  > **11:06 Franco Gilio:** ya está\n\n**09:30 Ana Pérez:** listo")
        // raw/ is what a render of messages.jsonl gives, so the intake's check passes.
        ->and((new MarkdownRebuilder(new FakeNameResolver(['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'])))->firstDifference($dir))->toBeNull();
});

it('leaves the archive untouched when no thread moved', function () {
    $dir = archiveDir();

    (new ChannelArchiver(threadedClient()))->archive(archiveRequest($dir));
    $before = file_get_contents($dir.'/messages.jsonl');

    $summary = (new ChannelArchiver(threadedClient(latestReply: TS_0920)))->archive(archiveRequest($dir, ['sinceLast' => true]));

    expect($summary->lateReplies)->toBe(0)
        ->and(file_get_contents($dir.'/messages.jsonl'))->toBe($before);
});

it('skips the pass when the lookback is zero', function () {
    $dir = archiveDir();

    (new ChannelArchiver(threadedClient()))->archive(archiveRequest($dir));

    $later = threadedClient([lateReply()], replyCount: 2, latestReply: TS_LATE_REPLY);
    $summary = (new ChannelArchiver($later))->archive(archiveRequest($dir, ['sinceLast' => true, 'lateReplyDays' => 0]));

    expect($summary->lateReplies)->toBe(0)
        ->and(file_get_contents($dir.'/messages.jsonl'))->not->toContain('ya está');
});

it('ignores threads older than the lookback', function () {
    $dir = archiveDir();

    (new ChannelArchiver(threadedClient()))->archive(archiveRequest($dir));

    // The newest message moves 40 days on, which puts the thread outside a 30 day window.
    $fortyDaysOn = [[['ts' => sprintf('%d.000100', (int) TS_NEXT_DAY + 40 * 86400), 'user' => 'U01', 'text' => 'cuarenta días después']]];
    (new ChannelArchiver(threadedClient(extraPages: $fortyDaysOn)))->archive(archiveRequest($dir, ['sinceLast' => true]));

    $later = threadedClient([lateReply()], replyCount: 2, latestReply: TS_LATE_REPLY, extraPages: $fortyDaysOn);
    $summary = (new ChannelArchiver($later))->archive(archiveRequest($dir, ['sinceLast' => true]));

    expect($summary->lateReplies)->toBe(0)
        ->and(file_get_contents($dir.'/messages.jsonl'))->not->toContain('ya está');
});

it('appends new messages and late replies in the same run', function () {
    $dir = archiveDir();

    (new ChannelArchiver(threadedClient()))->archive(archiveRequest($dir));

    $later = threadedClient(
        [lateReply()],
        replyCount: 2,
        latestReply: TS_LATE_REPLY,
        extraPages: [[['ts' => TS_LATER, 'user' => 'U02', 'text' => 'una más']]],
    );
    $summary = (new ChannelArchiver($later))->archive(archiveRequest($dir, ['sinceLast' => true]));

    expect($summary->messages)->toBe(1)
        ->and($summary->lateReplies)->toBe(1)
        ->and(array_column(iterator_to_array(Jsonl::read($dir.'/messages.jsonl')), 'ts'))
        ->toBe([TS_0914, TS_0920, TS_LATE_REPLY, TS_0930, TS_NEXT_DAY, TS_LATER]);
});

it('reports late replies as an archived batch entry', function () {
    $dir = archiveDir();

    (new ChannelArchiver(threadedClient()))->archive(archiveRequest($dir));

    $later = threadedClient([lateReply()], replyCount: 2, latestReply: TS_LATE_REPLY);
    $summary = (new ChannelArchiver($later))->archive(archiveRequest($dir, ['sinceLast' => true]));
    $result = BatchResult::archived(new BatchEntry('#eng-leadership', $dir), $summary);

    expect($result->status())->toBe(BatchResult::STATUS_ARCHIVED)
        ->and($result->outcome())->toBe('0 messages, 0 replies appended, 1 late reply in 1 older thread')
        ->and($result->toArray()['late_replies'])->toBe(1);
});
