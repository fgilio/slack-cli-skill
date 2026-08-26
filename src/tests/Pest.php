<?php

use App\Archive\ArchiveRequest;
use App\Archive\Jsonl;
use Tests\Support\FakeSlackClient;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// The binary drops illuminate/testing to stay small, so the suite runs on
// plain PHPUnit test cases rather than booting the console application.

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// 1785575640 is 2026-08-01 09:14:00 UTC.
const TS_0914 = '1785575640.000100';
const TS_0920 = '1785576000.000200';
const TS_0930 = '1785576600.000300';
const TS_NEXT_DAY = '1785665100.000400';
const TS_LATER = '1785668700.000500';

function archiveDir(): string
{
    $dir = sys_get_temp_dir().'/slack-cli-run-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    return $dir;
}

/**
 * Ten messages an hour apart, dealt into five pages the way
 * conversations.history delivers them: newest page first, newest message
 * first inside each page.
 *
 * @return list<list<array<string, mixed>>>
 */
function pagedFixture(): array
{
    $messages = [];

    for ($step = 9; $step >= 0; $step--) {
        $messages[] = [
            'ts' => (string) (1785575640 + $step * 3600).'.000100',
            'user' => 'U01',
            'text' => "mensaje {$step}",
        ];
    }

    return array_chunk($messages, 2);
}

function pagedClient(?int $crashAfterPages = null, bool $forwardWhenFloored = true): FakeSlackClient
{
    return new FakeSlackClient(
        pages: pagedFixture(),
        users: ['U01' => 'Franco Gilio'],
        crashAfterPages: $crashAfterPages,
        forwardWhenFloored: $forwardWhenFloored,
    );
}

function resumeRequest(string $dir, bool $resume = false, ?string $oldest = null): ArchiveRequest
{
    return new ArchiveRequest(
        channelId: 'C47JM9E9K',
        outDir: $dir,
        oldest: $oldest,
        resume: $resume,
    );
}

/**
 * @return list<string>
 */
function archivedTimestamps(string $dir): array
{
    return array_column(iterator_to_array(Jsonl::read($dir.'/messages.jsonl')), 'ts');
}

/**
 * The timestamps of the channel's own messages, with thread replies left
 * out: a reply is written under its parent and so runs behind it by design.
 *
 * @return list<string>
 */
function topLevelTimestamps(string $dir): array
{
    $timestamps = [];

    foreach (Jsonl::read($dir.'/messages.jsonl') as $message) {
        $ts = (string) ($message['ts'] ?? '');
        $threadTs = $message['thread_ts'] ?? null;

        if (is_string($threadTs) && $threadTs !== $ts) {
            continue;
        }

        $timestamps[] = $ts;
    }

    return $timestamps;
}

/**
 * How many times a list of timestamps steps backwards.
 *
 * @param  list<string>  $timestamps
 */
function orderInversions(array $timestamps): int
{
    $inversions = 0;

    for ($index = 1; $index < count($timestamps); $index++) {
        if (Jsonl::compareTimestamps($timestamps[$index], $timestamps[$index - 1]) < 0) {
            $inversions++;
        }
    }

    return $inversions;
}

/**
 * Two days of a channel: one threaded message, one plain answer, and a
 * message the next morning.
 */
function fixtureClient(): FakeSlackClient
{
    return new FakeSlackClient(
        pages: [
            [['ts' => TS_NEXT_DAY, 'user' => 'U01', 'text' => 'seguimos mañana']],
            [
                ['ts' => TS_0930, 'user' => 'U02', 'text' => 'listo'],
                ['ts' => TS_0914, 'user' => 'U01', 'text' => '¿arrancamos?', 'thread_ts' => TS_0914, 'reply_count' => 1],
            ],
        ],
        threads: [
            TS_0914 => [
                ['ts' => TS_0914, 'user' => 'U01', 'text' => '¿arrancamos?'],
                ['ts' => TS_0920, 'user' => 'U02', 'text' => 'dale'],
            ],
        ],
        users: ['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'],
    );
}
