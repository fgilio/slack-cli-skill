<?php

use App\Archive\ArchiveRequest;
use App\Archive\ChannelArchiver;
use App\Commands\ArchiveRenderCommand;
use App\Services\SlackClient;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\ConsoleContainer;
use Tests\Support\FakeSlackClient;

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

    return (string) file_get_contents($dir.'/raw.md');
}

it('writes the same raw.md the archive run wrote', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir);

    file_put_contents($dir.'/raw.md', strtok($archived, "\n")."\n\nWorkspace: publica.la. Canal privado, 12 miembros.\nHorarios en UTC .\n\nstale body\n");

    expect(renderTester()->execute(['dir' => $dir]))->toBe(0)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($archived)
        ->and($archived)->toContain(
            "**09:14 Franco Gilio:** ¿arrancamos?\n"
            ."  > **09:20 Ana Pérez:** dale\n"
            ."\n**09:30 Ana Pérez:** [mensaje compartido de Franco Gilio, 2026-08-01 09:14]\n"
            ."  > ¿arrancamos?\n"
            ."  > hoy\n"
        );
});

it('renders an archive extended with --since-last as one continuous copy', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    $later = [[['ts' => TS_LATER, 'user' => 'U02', 'text' => 'una más']]];
    $extended = archivedWith(sharedClient($later), $dir, sinceLast: true);

    expect(renderTester()->execute(['dir' => $dir]))->toBe(0)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($extended)
        ->and(substr_count($extended, '## 2026-08-02'))->toBe(1);
});

it('keeps a reply sent to the channel on its own line when the run skipped threads', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir, threads: false);

    expect(renderTester()->execute(['dir' => $dir]))->toBe(0)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($archived)
        ->and($archived)->toContain("\n**09:20 Ana Pérez:** dale\n");
});

it('renders each segment of an archive with the thread setting of the run that wrote it', function (bool $first, bool $later) {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir, threads: $first);

    $laterPages = [[
        ['ts' => '1785668760.000700', 'user' => 'U01', 'text' => 'ok', 'thread_ts' => TS_LATER, 'subtype' => 'thread_broadcast'],
        ['ts' => TS_LATER, 'user' => 'U02', 'text' => 'una más', 'thread_ts' => TS_LATER, 'reply_count' => 1],
    ]];
    $extended = archivedWith(new FakeSlackClient(
        pages: [...$laterPages],
        threads: [TS_LATER => [
            ['ts' => TS_LATER, 'user' => 'U02', 'text' => 'una más', 'thread_ts' => TS_LATER],
            ['ts' => '1785668760.000700', 'user' => 'U01', 'text' => 'ok', 'thread_ts' => TS_LATER, 'subtype' => 'thread_broadcast'],
        ]],
        users: ['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'],
    ), $dir, threads: $later, sinceLast: true);

    expect(renderTester()->execute(['dir' => $dir]))->toBe(0)
        ->and(file_get_contents($dir.'/raw.md'))->toBe($extended);
})->with([
    'threads, then --no-threads' => [true, false],
    '--no-threads, then threads' => [false, true],
]);

it('nests replies when the metadata predates the thread settings', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir);

    file_put_contents($dir.'/.archive-meta.json', json_encode(['version' => 1, 'channel_id' => 'C47JM9E9K', 'channel' => '#eng-leadership']));

    renderTester()->execute(['dir' => $dir]);

    expect(file_get_contents($dir.'/raw.md'))->toBe($archived);
});

it('leaves messages.jsonl untouched', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    $before = md5_file($dir.'/messages.jsonl');

    renderTester()->execute(['dir' => $dir]);

    expect(md5_file($dir.'/messages.jsonl'))->toBe($before);
});

it('fetches no messages from Slack', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    $client = sharedClient();
    renderTester($client)->execute(['dir' => $dir]);

    expect($client->historyCalls)->toBe(0);
});

it('reports what it rendered', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    $tester = renderTester();
    $tester->execute(['dir' => $dir, '--json' => true]);

    expect(json_decode($tester->getDisplay(), true)['data'])->toMatchArray([
        'channel_id' => 'C47JM9E9K',
        'channel' => '#eng-leadership',
        'messages' => 3,
        'threads' => 1,
        'replies' => 1,
        'first_day' => '2026-08-01',
        'last_day' => '2026-08-02',
    ]);
});

it('refuses a directory holding an interrupted archive run', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir);

    file_put_contents(ArchiveRequest::checkpointPathIn($dir), '{}');

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir]))->toBe(1)
        ->and($tester->getDisplay())->toContain('holds an interrupted archive run')
        ->and(file_get_contents($dir.'/raw.md'))->toBe($archived);
});

it('refuses a raw.md without an archive header', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    file_put_contents($dir.'/raw.md', "# Notas\n");

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Unable to read the archive header')
        ->and(file_get_contents($dir.'/raw.md'))->toBe("# Notas\n")
        ->and(is_file($dir.'/raw.md.part'))->toBeFalse();
});

it('refuses a directory without messages.jsonl', function () {
    $tester = renderTester();

    expect($tester->execute(['dir' => archiveDir()]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Unable to find messages.jsonl');
});

it('refuses a directory that does not exist', function () {
    $tester = renderTester();

    expect($tester->execute(['dir' => archiveDir().'/missing']))->toBe(1)
        ->and($tester->getDisplay())->toContain('Unable to find the archive directory');
});
