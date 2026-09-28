<?php

use Symfony\Component\Process\Process;

/**
 * Runs the real entry script, since the colour decision is made there,
 * before any command. FORCE_COLOR makes Symfony colour even a pipe.
 */
function runEntryScript(string ...$arguments): Process
{
    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2).'/slack-cli', ...$arguments],
        env: ['FORCE_COLOR' => '3'],
    );

    $process->run();

    return $process;
}

it('prints valid JSON under --json even when colour is forced', function () {
    $root = archiveDir();
    // A console style tag in the data is what Symfony would turn into escape codes.
    plantArchive($root.'/<comment>release</comment>', metadata: ['version' => 1, 'channel_id' => 'C47JM9E9K', 'channel' => '#release']);

    $process = runEntryScript('archive:batch', '--init', $root, '--json');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->not->toContain("\e[")
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['data'][0]['target'])->toBe('C47JM9E9K');
});

it('keeps escape codes out of the errors Symfony prints under --json', function () {
    $process = runEntryScript('archive', '--json');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain("\e[")
        ->and($process->getOutput().$process->getErrorOutput())->toContain('Not enough arguments');
});
