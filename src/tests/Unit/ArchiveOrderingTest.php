<?php

use App\Archive\ChannelArchiver;
use App\Archive\Jsonl;
use Tests\Support\FakeSlackClient;

/**
 * The floor that lets every message in, so `--after` is exercised without
 * narrowing what comes back. Any narrowing would hide a page-ordering
 * fault behind a smaller result.
 */
const FLOOR_BEFORE_EVERYTHING = '1785000000.000000';

/**
 * @return list<string>
 */
function markdownDays(string $dir): array
{
    preg_match_all('/^## (\d{4}-\d{2}-\d{2})$/m', (string) file_get_contents($dir.'/raw.md'), $found);

    return $found[1];
}

it('serves the oldest slice first once a floor is given, the way Slack does', function () {
    $client = pagedClient();

    $floored = [];

    foreach ($client->historyPages('C47JM9E9K', FLOOR_BEFORE_EVERYTHING) as $page) {
        $floored[] = (string) $page['messages'][0]['ts'];
    }

    $unfloored = [];

    foreach (pagedClient()->historyPages('C47JM9E9K') as $page) {
        $unfloored[] = (string) $page['messages'][0]['ts'];
    }

    // Each page still leads with its newest message, but the pages
    // themselves arrive in the opposite order.
    expect($floored)->toBe(array_reverse($unfloored));
});

it('archives a fresh run with a floor in strict chronological order', function () {
    $dir = archiveDir();

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, oldest: FLOOR_BEFORE_EVERYTHING));

    $timestamps = topLevelTimestamps($dir);
    $sorted = $timestamps;
    sort($sorted);

    expect($timestamps)->toHaveCount(10)
        ->and(orderInversions($timestamps))->toBe(0)
        ->and($timestamps)->toBe($sorted)
        ->and(markdownDays($dir))->toBe(['2026-08-01']);
});

it('archives a fresh run without a floor in strict chronological order', function () {
    $dir = archiveDir();

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir));

    expect(orderInversions(topLevelTimestamps($dir)))->toBe(0)
        ->and(topLevelTimestamps($dir))->toHaveCount(10);
});

it('gives a floored run the same archive as an unfloored one', function () {
    $floored = archiveDir();
    $unfloored = archiveDir();

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($floored, oldest: FLOOR_BEFORE_EVERYTHING));
    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($unfloored));

    expect(archivedTimestamps($floored))->toBe(archivedTimestamps($unfloored))
        ->and(file_get_contents($floored.'/raw.md'))->toBe(file_get_contents($unfloored.'/raw.md'));
});

it('resumes a floored run killed mid-history in strict chronological order', function () {
    $dir = archiveDir();

    try {
        (new ChannelArchiver(pagedClient(crashAfterPages: 2)))
            ->archive(resumeRequest($dir, oldest: FLOOR_BEFORE_EVERYTHING));
    } catch (RuntimeException) {
        // The kill is the point of the fixture.
    }

    (new ChannelArchiver(pagedClient()))
        ->archive(resumeRequest($dir, resume: true, oldest: FLOOR_BEFORE_EVERYTHING));

    $unfloored = archiveDir();
    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($unfloored));

    expect(orderInversions(topLevelTimestamps($dir)))->toBe(0)
        ->and(archivedTimestamps($dir))->toBe(archivedTimestamps($unfloored))
        ->and(file_get_contents($dir.'/raw.md'))->toBe(file_get_contents($unfloored.'/raw.md'));
});

it('resumes an unfloored run killed mid-history in strict chronological order', function () {
    $dir = archiveDir();

    try {
        (new ChannelArchiver(pagedClient(crashAfterPages: 2)))->archive(resumeRequest($dir));
    } catch (RuntimeException) {
        // The kill is the point of the fixture.
    }

    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($dir, resume: true));

    expect(orderInversions(topLevelTimestamps($dir)))->toBe(0)
        ->and(topLevelTimestamps($dir))->toHaveCount(10);
});

it('orders a run whose pages arrive in any order at all', function () {
    $dir = archiveDir();

    // Pages shuffled into an order neither the forward nor the backward walk
    // would produce: nothing about arrival order may reach the archive.
    $shuffled = pagedFixture();
    $shuffled = [$shuffled[2], $shuffled[0], $shuffled[4], $shuffled[1], $shuffled[3]];

    $client = new FakeSlackClient(
        pages: $shuffled,
        users: ['U01' => 'Franco Gilio'],
        forwardWhenFloored: false,
    );

    (new ChannelArchiver($client))->archive(resumeRequest($dir));

    $reference = archiveDir();
    (new ChannelArchiver(pagedClient()))->archive(resumeRequest($reference));

    expect(orderInversions(topLevelTimestamps($dir)))->toBe(0)
        ->and(archivedTimestamps($dir))->toBe(archivedTimestamps($reference));
});

it('orders a page whose messages arrive oldest first', function () {
    $dir = archiveDir();

    // Some Slack responses hand a page back ascending. Reversing blindly
    // would turn exactly those pages upside down.
    $ascending = array_map(fn (array $page) => array_reverse($page), pagedFixture());

    $client = new FakeSlackClient(
        pages: array_reverse($ascending),
        users: ['U01' => 'Franco Gilio'],
        forwardWhenFloored: false,
    );

    (new ChannelArchiver($client))->archive(resumeRequest($dir));

    expect(orderInversions(topLevelTimestamps($dir)))->toBe(0)
        ->and(topLevelTimestamps($dir))->toHaveCount(10);
});

it('keeps two messages in the same second apart by their microseconds', function () {
    expect(Jsonl::compareTimestamps('1785575640.000100', '1785575640.000099'))->toBe(1)
        ->and(Jsonl::compareTimestamps('1785575640.000099', '1785575640.000100'))->toBe(-1)
        ->and(Jsonl::compareTimestamps('1785575640.000100', '1785575640.000100'))->toBe(0)
        ->and(Jsonl::compareTimestamps('1785575641.000000', '1785575640.999999'))->toBe(1);
});

it('sorts a page by timestamp rather than by arrival', function () {
    $sorted = Jsonl::sortByTimestamp([
        ['ts' => '1785575640.000300'],
        ['ts' => '1785575640.000100'],
        ['ts' => '1785575639.000900'],
    ]);

    expect(array_column($sorted, 'ts'))
        ->toBe(['1785575639.000900', '1785575640.000100', '1785575640.000300']);
});
