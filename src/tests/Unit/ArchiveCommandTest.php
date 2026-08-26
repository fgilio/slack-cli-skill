<?php

use App\Commands\ArchiveCommand;
use App\Services\SlackClient;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\ConsoleContainer;
use Tests\Support\FakeSlackClient;

function archiveTester(SlackClient $client): CommandTester
{
    $command = new ArchiveCommand($client);
    $command->setLaravel(new ConsoleContainer);
    $command->getDefinition()->addOption(new InputOption('json', null, InputOption::VALUE_NONE));

    return new CommandTester($command);
}

it('exits zero after archiving a conversation', function () {
    $dir = archiveDir();

    $tester = archiveTester(fixtureClient());

    expect($tester->execute(['target' => 'engineering-team', '--out' => $dir]))->toBe(0)
        ->and($tester->getDisplay())->toContain('3 messages, 1 threads, 1 replies')
        ->and(is_file($dir.'/messages.jsonl'))->toBeTrue();
});

it('exits non-zero when no output directory is given', function () {
    $tester = archiveTester(fixtureClient());

    expect($tester->execute(['target' => 'engineering-team']))->toBe(1)
        ->and($tester->getDisplay())->toContain('The --out option is required');
});

it('exits non-zero when the target cannot be resolved', function () {
    $tester = archiveTester(fixtureClient());

    expect($tester->execute(['target' => 'missing', '--out' => archiveDir()]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Channel not found');
});

it('exits non-zero when the archiver refuses the directory', function () {
    $dir = archiveDir();

    archiveTester(fixtureClient())->execute(['target' => 'engineering-team', '--out' => $dir]);

    $tester = archiveTester(fixtureClient());

    expect($tester->execute(['target' => 'engineering-team', '--out' => $dir]))->toBe(1)
        ->and($tester->getDisplay())->toContain('already holds an archive');
});

it('exits non-zero when an interrupted run needs --resume', function () {
    $dir = archiveDir();

    try {
        archiveTester(new FakeSlackClient(
            pages: pagedFixture(),
            users: ['U01' => 'Franco Gilio'],
            crashAfterPages: 2,
        ))->execute(['target' => 'engineering-team', '--out' => $dir]);
    } catch (RuntimeException) {
        // CommandTester rethrows what the archiver threw.
    }

    $tester = archiveTester(fixtureClient());

    expect($tester->execute(['target' => 'engineering-team', '--out' => $dir]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Pass --resume');
});

it('exits non-zero on a bad date', function () {
    $tester = archiveTester(fixtureClient());

    expect($tester->execute(['target' => 'engineering-team', '--out' => archiveDir(), '--after' => '25/08/2025']))->toBe(1)
        ->and($tester->getDisplay())->toContain("Unable to read the date '25/08/2025'");
});

it('still exits non-zero when the failure is reported as JSON', function () {
    $tester = archiveTester(fixtureClient());

    expect($tester->execute(['target' => 'missing', '--out' => archiveDir(), '--json' => true]))->toBe(1);
});

it('exits zero and prints the summary as JSON', function () {
    $tester = archiveTester(fixtureClient());

    $exit = $tester->execute(['target' => 'engineering-team', '--out' => archiveDir(), '--json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode($tester->getDisplay(), true)['data'])->toMatchArray([
            'channel' => '#eng-leadership',
            'messages' => 3,
            'replies' => 1,
        ]);
});
