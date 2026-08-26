<?php

use App\Commands\ArchiveBatchCommand;
use App\Services\SlackClient;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\ConsoleContainer;

/**
 * The binary drops illuminate/testing, so the command runs on a bare
 * container rather than a booted application. The global --json option
 * the foundation registers at Artisan startup is added by hand for the
 * same reason.
 */
function batchTester(SlackClient $client): CommandTester
{
    $command = new ArchiveBatchCommand($client);
    $command->setLaravel(new ConsoleContainer);
    $command->getDefinition()->addOption(new InputOption('json', null, InputOption::VALUE_NONE));

    return new CommandTester($command);
}

function batchManifestFile(array $entries): string
{
    $path = archiveDir().'/manifest.json';
    file_put_contents($path, (string) json_encode($entries));

    return $path;
}

it('reports every entry it archived', function () {
    $gonza = archiveDir();
    $eng = archiveDir();

    $tester = batchTester(fixtureClient());

    $exit = $tester->execute(['paths' => [batchManifestFile([
        ['target' => '@gparra', 'out' => $gonza],
        ['target' => 'engineering-team', 'out' => $eng],
    ])]]);

    $display = $tester->getDisplay();

    expect($exit)->toBe(0)
        ->and($display)->toContain('[1/2] @gparra')
        ->and($display)->toContain('[2/2] engineering-team')
        ->and($display)->toContain('3 messages, 1 reply appended')
        ->and($display)->toContain('2 of 2 archived.')
        ->and(is_file($gonza.'/messages.jsonl'))->toBeTrue()
        ->and(is_file($eng.'/messages.jsonl'))->toBeTrue();
});

it('finishes the batch and fails the run when an entry breaks', function () {
    $tester = batchTester(fixtureClient());

    $exit = $tester->execute(['paths' => [batchManifestFile([
        ['target' => 'missing', 'out' => archiveDir()],
        ['target' => 'engineering-team', 'out' => archiveDir()],
    ])]]);

    $display = $tester->getDisplay();

    expect($exit)->toBe(1)
        ->and($display)->toContain('FAILED: Channel not found. Check the name or ID')
        ->and($display)->toContain('1 of 2 archived, 1 failed.')
        ->and($display)->toContain('missing: Channel not found');
});

it('archives only the entries a glob selects', function () {
    $gonza = archiveDir();
    $eng = archiveDir();

    $tester = batchTester(fixtureClient());

    $exit = $tester->execute([
        'paths' => [batchManifestFile([
            ['target' => '@gparra', 'out' => $gonza],
            ['target' => 'engineering-team', 'out' => $eng],
        ])],
        '--only' => 'gparra',
    ]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('[1/1] @gparra')
        ->and(is_file($gonza.'/messages.jsonl'))->toBeTrue()
        ->and(is_file($eng.'/messages.jsonl'))->toBeFalse();
});

it('refuses a glob that selects nothing', function () {
    $tester = batchTester(fixtureClient());

    $exit = $tester->execute([
        'paths' => [batchManifestFile([['target' => '@gparra', 'out' => archiveDir()]])],
        '--only' => 'design',
    ]);

    expect($exit)->toBe(1)
        ->and($tester->getDisplay())->toContain('No manifest entry matches --only=design');
});

it('refuses a broken manifest before it archives anything', function () {
    $out = archiveDir();

    $tester = batchTester(fixtureClient());

    $exit = $tester->execute(['paths' => [batchManifestFile([
        ['target' => '@gparra', 'out' => $out],
        ['out' => '/tmp/nameless'],
    ])]]);

    expect($exit)->toBe(1)
        ->and($tester->getDisplay())->toContain("Entry 2 needs a 'target'")
        ->and(is_file($out.'/messages.jsonl'))->toBeFalse();
});

it('asks for a manifest when none is given', function () {
    $tester = batchTester(fixtureClient());

    expect($tester->execute([]))->toBe(1)
        ->and($tester->getDisplay())->toContain('exactly one manifest file');
});

it('emits a machine-readable summary', function () {
    $tester = batchTester(fixtureClient());

    $exit = $tester->execute([
        'paths' => [batchManifestFile([
            ['target' => 'engineering-team', 'out' => archiveDir()],
            ['target' => 'missing', 'out' => archiveDir()],
        ])],
        '--json' => true,
    ]);

    $payload = json_decode($tester->getDisplay(), true);

    expect($exit)->toBe(1)
        ->and($payload['data'])->toHaveCount(2)
        ->and($payload['data'][0])->toMatchArray([
            'target' => 'engineering-team',
            'status' => 'archived',
            'channel' => '#eng-leadership',
            'channel_id' => 'C47JM9E9K',
            'messages' => 3,
            'replies' => 1,
            'threads' => 1,
        ])
        ->and($payload['data'][1])->toMatchArray([
            'target' => 'missing',
            'status' => 'failed',
            'messages' => 0,
            'error' => 'Channel not found. Check the name or ID',
        ]);
});

it('reports an archive with nothing new as up to date', function () {
    $out = archiveDir();
    $manifest = batchManifestFile([['target' => 'engineering-team', 'out' => $out]]);

    batchTester(fixtureClient())->execute(['paths' => [$manifest]]);

    $tester = batchTester(fixtureClient());
    $exit = $tester->execute(['paths' => [$manifest], '--since-last' => true]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('up to date');
});

it('builds a manifest from the archives a batch just wrote', function () {
    $root = archiveDir();

    batchTester(fixtureClient())->execute(['paths' => [batchManifestFile([
        ['target' => 'engineering-team', 'out' => $root.'/Equipo/engineering-team'],
    ])]]);

    $tester = batchTester(fixtureClient());
    $exit = $tester->execute(['paths' => [$root], '--init' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode($tester->getDisplay(), true))->toBe([
            ['target' => 'C47JM9E9K', 'out' => $root.'/Equipo/engineering-team'],
        ]);
});

it('refuses to build a manifest from a tree with no archives', function () {
    $tester = batchTester(fixtureClient());

    expect($tester->execute(['paths' => [archiveDir()], '--init' => true]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Found no archives in those directories');
});
