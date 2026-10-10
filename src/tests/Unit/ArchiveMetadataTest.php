<?php

use App\Archive\ArchiveMetadata;

it('starts one segment on a fresh run', function () {
    $metadata = (new ArchiveMetadata('C01', '#general', [['after' => null, 'nested' => false]]))
        ->withRun('C01', '#general', null, true);

    expect($metadata->threadSegments)->toBe([['after' => null, 'nested' => true]]);
});

it('keeps one segment while incremental runs share the thread setting', function () {
    $metadata = (new ArchiveMetadata('C01', '#general'))
        ->withRun('C01', '#general', null, false)
        ->withRun('C01', '#general', TS_0930, false);

    expect($metadata->threadSegments)->toBe([['after' => null, 'nested' => false]]);
});

it('starts a segment where an incremental run changes the thread setting', function () {
    $metadata = (new ArchiveMetadata('C01', '#general'))
        ->withRun('C01', '#general', null, true)
        ->withRun('C01', '#general', TS_0930, false);

    expect($metadata->threadSegments)->toBe([
        ['after' => null, 'nested' => true],
        ['after' => TS_0930, 'nested' => false],
    ])
        ->and($metadata->nestsRepliesAt(TS_0914))->toBeTrue()
        ->and($metadata->nestsRepliesAt(TS_0930))->toBeTrue()
        ->and($metadata->nestsRepliesAt(TS_NEXT_DAY))->toBeFalse();
});

it('treats a file written before thread settings were recorded as nested', function () {
    $metadata = new ArchiveMetadata('C01', '#general');

    expect($metadata->nestsRepliesAt(TS_0914))->toBeTrue()
        ->and($metadata->withRun('C01', '#general', TS_0930, false)->threadSegments)->toBe([
            ['after' => null, 'nested' => true],
            ['after' => TS_0930, 'nested' => false],
        ]);
});

it('round-trips the thread segments through the file', function () {
    $dir = archiveDir();

    (new ArchiveMetadata('C01', '#general', [['after' => null, 'nested' => true], ['after' => TS_0930, 'nested' => false]]))
        ->save($dir);

    expect(ArchiveMetadata::load($dir)?->threadSegments)
        ->toBe([['after' => null, 'nested' => true], ['after' => TS_0930, 'nested' => false]]);
});

it('ignores thread segments it cannot read', function () {
    $dir = archiveDir();

    file_put_contents($dir.'/.archive-meta.json', json_encode(['version' => 1, 'channel_id' => 'C01', 'threads' => true]));

    expect(ArchiveMetadata::load($dir)?->threadSegments)->toBe([]);
});
