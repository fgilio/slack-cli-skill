<?php

use App\Archive\ArchiveCheckpoint;

function checkpointPath(): string
{
    $dir = sys_get_temp_dir().'/slack-cli-checkpoint-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    register_shutdown_function(function () use ($dir) {
        foreach (glob($dir.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($dir);
    });

    return $dir.'/.archive-checkpoint.json';
}

function checkpoint(string $path): ArchiveCheckpoint
{
    return new ArchiveCheckpoint(
        path: $path,
        channelId: 'C47JM9E9K',
        oldest: '1785542400.000000',
        latest: '1785974399.999999',
        threads: true,
        sinceLast: false,
    );
}

it('round trips every field it saves', function () {
    $path = checkpointPath();

    $saved = checkpoint($path);
    $saved->phase = ArchiveCheckpoint::PHASE_THREADS;
    $saved->cursor = 'dXNlcjpVMDYxTkZUVDI=';
    $saved->pageCount = 37;
    $saved->threadLine = 412;
    $saved->messagesBytes = 91234;
    $saved->markdownBytes = 5120;
    $saved->sinceTs = '1785542401.000100';
    $saved->save();

    expect(ArchiveCheckpoint::load($path)?->toArray())->toBe($saved->toArray());
});

it('reports no checkpoint for a directory without one', function () {
    expect(ArchiveCheckpoint::load(checkpointPath()))->toBeNull();
});

it('rejects a checkpoint written by another version', function () {
    $path = checkpointPath();
    file_put_contents($path, json_encode(['version' => 99]));

    expect(fn () => ArchiveCheckpoint::load($path))
        ->toThrow(RuntimeException::class, 'different version');
});

it('leaves no partial file behind after saving', function () {
    $path = checkpointPath();
    checkpoint($path)->save();

    expect(file_exists($path.'.part'))->toBeFalse();
});

it('recognises the run it belongs to', function () {
    $stored = checkpoint(checkpointPath());

    expect($stored->covers('C47JM9E9K', '1785542400.000000', '1785974399.999999', true, false))->toBeTrue();
});

it('refuses a run against a different channel or window', function () {
    $stored = checkpoint(checkpointPath());

    expect($stored->covers('C00OTHER', '1785542400.000000', '1785974399.999999', true, false))->toBeFalse()
        ->and($stored->covers('C47JM9E9K', null, '1785974399.999999', true, false))->toBeFalse()
        ->and($stored->covers('C47JM9E9K', '1785542400.000000', '1785974399.999999', false, false))->toBeFalse()
        ->and($stored->covers('C47JM9E9K', '1785542400.000000', '1785974399.999999', true, true))->toBeFalse();
});

it('removes itself when a run finishes', function () {
    $path = checkpointPath();
    $stored = checkpoint($path);
    $stored->save();
    $stored->delete();

    expect(file_exists($path))->toBeFalse();
});
