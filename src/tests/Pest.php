<?php

use App\Archive\ArchiveRequest;
use App\Archive\ChannelArchiver;
use App\Archive\Jsonl;
use App\Commands\ArchiveRenderCommand;
use App\Services\SlackClient;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\ConsoleContainer;
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

/**
 * A fresh path for one test, inside a scratch root this test process owns.
 * The root, and everything the tests write under it, is removed when the
 * process exits, so a run leaves nothing behind in the system temp folder.
 */
function testTempPath(string $name): string
{
    static $root = null;

    if ($root === null) {
        $root = sys_get_temp_dir().'/slack-cli-tests-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($root, 0755, true);

        register_shutdown_function(fn () => removeTree($root));
    }

    return $root.'/'.$name.'-'.bin2hex(random_bytes(6));
}

/**
 * A fresh, empty directory for one test. See testTempPath().
 */
function testTempDir(string $name): string
{
    $dir = testTempPath($name);
    mkdir($dir, 0755, true);

    return $dir;
}

function removeTree(string $path): void
{
    if (! is_dir($path) || is_link($path)) {
        @unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') {
            removeTree($path.'/'.$child);
        }
    }

    @rmdir($path);
}

// 1785575640 is 2026-08-01 09:14:00 UTC.
const TS_0914 = '1785575640.000100';
const TS_0920 = '1785576000.000200';
const TS_0930 = '1785576600.000300';
const TS_NEXT_DAY = '1785665100.000400';
const TS_LATER = '1785668700.000500';
// 2026-09-01 09:14 and 2026-10-01 09:14 UTC.
const TS_SEPTEMBER = '1788254040.000600';
const TS_OCTOBER = '1790846040.000700';

function renderTester(?SlackClient $client = null): CommandTester
{
    $command = new ArchiveRenderCommand($client ?? sharedClient());
    $command->setLaravel(new ConsoleContainer);
    $command->getDefinition()->addOption(new InputOption('json', null, InputOption::VALUE_NONE));

    return new CommandTester($command);
}

/**
 * A thread whose replies carry thread_ts the way Slack sends them, a
 * message shared without a comment, and a reply also sent to the channel.
 *
 * @param  list<list<array<string, mixed>>>  $laterPages  Newest page first.
 */
function sharedClient(array $laterPages = []): FakeSlackClient
{
    return new FakeSlackClient(
        pages: [
            ...$laterPages,
            [
                ['ts' => TS_NEXT_DAY, 'user' => 'U01', 'text' => 'seguimos mañana'],
                [
                    'ts' => TS_0930,
                    'user' => 'U02',
                    'text' => '',
                    'attachments' => [[
                        'is_share' => true,
                        'is_msg_unfurl' => true,
                        'ts' => TS_0914,
                        'author_name' => 'Franco Gilio',
                        'text' => "¿arrancamos?\nhoy",
                    ]],
                ],
                [
                    'ts' => TS_0920,
                    'user' => 'U02',
                    'text' => 'dale',
                    'thread_ts' => TS_0914,
                    'subtype' => 'thread_broadcast',
                ],
                ['ts' => TS_0914, 'user' => 'U01', 'text' => '¿arrancamos?', 'thread_ts' => TS_0914, 'reply_count' => 1],
            ],
        ],
        threads: [
            TS_0914 => [
                ['ts' => TS_0914, 'user' => 'U01', 'text' => '¿arrancamos?', 'thread_ts' => TS_0914],
                ['ts' => TS_0920, 'user' => 'U02', 'text' => 'dale', 'thread_ts' => TS_0914, 'subtype' => 'thread_broadcast'],
            ],
        ],
        users: ['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'],
    );
}

function archivedWith(FakeSlackClient $client, string $dir, bool $threads = true, bool $sinceLast = false): string
{
    (new ChannelArchiver($client))->archive(new ArchiveRequest(
        channelId: 'C47JM9E9K',
        outDir: $dir,
        includeThreads: $threads,
        sinceLast: $sinceLast,
    ));

    return rawCopy($dir);
}

/**
 * A later page for sharedClient(): the first message of September.
 *
 * @return list<list<array<string, mixed>>>
 */
function crossMonthPages(): array
{
    return [[['ts' => TS_SEPTEMBER, 'user' => 'U02', 'text' => 'arranca septiembre']]];
}

function archiveDir(): string
{
    return testTempDir('run');
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

/**
 * The raw/ month files of an archive, keyed by month, oldest first.
 *
 * @return array<string, string>
 */
function rawMonths(string $dir): array
{
    $months = [];

    foreach (glob($dir.'/raw/*.md') ?: [] as $path) {
        $months[basename($path, '.md')] = (string) file_get_contents($path);
    }

    ksort($months);

    return $months;
}

/**
 * The whole reading copy as one string, each month file under its own
 * name, so one comparison covers which files exist and what they hold.
 */
function rawCopy(string $dir): string
{
    if (is_file($dir.'/raw.md')) {
        return '=== raw.md ==='."\n".file_get_contents($dir.'/raw.md');
    }

    return collect(rawMonths($dir))
        ->map(fn (string $contents, string $month): string => "=== raw/{$month}.md ===\n{$contents}")
        ->implode('');
}

/**
 * Turn an archive's raw/ back into the single raw.md an archive held
 * before raw/ existed: the dated title, the shared header, and every
 * month's days joined with one newline between months.
 */
function toLegacyLayout(string $dir): string
{
    $rest = null;
    $label = null;
    $days = [];

    foreach (rawMonths($dir) as $contents) {
        $titleEnd = strpos($contents, "\n");
        $daysStart = strpos($contents, "\n## ") + 1;

        $label ??= preg_replace('/^# (.+) — \d{4}-\d{2}$/u', '$1', substr($contents, 0, $titleEnd));
        $rest ??= substr($contents, $titleEnd + 1, $daysStart - $titleEnd - 1);
        $days[] = substr($contents, $daysStart);
    }

    $body = implode("\n", $days);
    preg_match_all('/^## (\d{4}-\d{2}-\d{2})$/m', $body, $found);

    $legacy = "# {$label} — Dump completo ({$found[1][0]} a ".end($found[1]).")\n".$rest.$body;

    foreach (glob($dir.'/raw/*') ?: [] as $path) {
        unlink($path);
    }

    rmdir($dir.'/raw');
    file_put_contents($dir.'/raw.md', $legacy);

    return $legacy;
}
