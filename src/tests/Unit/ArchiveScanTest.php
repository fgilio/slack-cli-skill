<?php

use App\Archive\ArchiveScan;
use App\Archive\BatchEntry;
use App\Archive\BatchRunner;
use App\Archive\ScannedArchive;
use App\Archive\SlackEntryArchiver;

function scanRoot(): string
{
    $dir = sys_get_temp_dir().'/slack-cli-scan-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    return $dir;
}

function plantArchive(string $dir, ?string $header = null, ?array $metadata = null): string
{
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/messages.jsonl', '{"ts":"1785575640.000100"}'."\n");

    if ($header !== null) {
        file_put_contents($dir.'/raw.md', $header."\n");
    }

    if ($metadata !== null) {
        file_put_contents($dir.'/.archive-meta.json', (string) json_encode($metadata));
    }

    return $dir;
}

it('finds every archive under a directory tree', function () {
    $root = scanRoot();
    plantArchive($root.'/People/Gonza/Slack-DM');
    plantArchive($root.'/Equipo/Slack/engineering-team');
    mkdir($root.'/Equipo/Slack/not-an-archive', 0755, true);

    expect(array_map(fn (ScannedArchive $found) => $found->outDir, ArchiveScan::directories([$root])))->toBe([
        $root.'/Equipo/Slack/engineering-team',
        $root.'/People/Gonza/Slack-DM',
    ]);
});

it('takes the target from the metadata an archive run leaves behind', function () {
    $root = scanRoot();
    plantArchive($root.'/eng', metadata: ['version' => 1, 'channel_id' => 'C47JM9E9K', 'channel' => '#eng-leadership']);

    $found = ArchiveScan::directories([$root])[0];

    expect($found->target)->toBe('C47JM9E9K')
        ->and($found->label)->toBe('#eng-leadership')
        ->and($found->toEntry()->toArray())->toBe(['target' => 'C47JM9E9K', 'out' => $root.'/eng']);
});

it('falls back to the channel name in the markdown header', function () {
    $root = scanRoot();
    plantArchive($root.'/eng', header: '# #eng-leadership — Dump completo (2026-08-01 a 2026-08-02)');

    expect(ArchiveScan::directories([$root])[0]->target)->toBe('#eng-leadership');
});

it('leaves a FIXME on a DM, whose header holds a real name rather than a handle', function () {
    $root = scanRoot();
    plantArchive($root.'/gonza', header: '# DM con Gonzalo Parra — Dump completo (2026-08-01 a 2026-08-02)');

    $found = ArchiveScan::directories([$root])[0];

    expect($found->target)->toBeNull()
        ->and($found->toEntry()->target)->toBe('FIXME')
        ->and($found->note())->toContain("archives 'DM con Gonzalo Parra'");
});

it('leaves a FIXME on an archive that says nothing about itself', function () {
    $root = scanRoot();
    plantArchive($root.'/mystery');

    $found = ArchiveScan::directories([$root])[0];

    expect($found->target)->toBeNull()
        ->and($found->label)->toBeNull()
        ->and($found->note())->toContain('carries no record of the conversation it holds');
});

it('ignores a metadata file with no channel in it', function () {
    $root = scanRoot();
    plantArchive($root.'/eng', metadata: ['version' => 1]);

    expect(ArchiveScan::directories([$root])[0]->target)->toBeNull();
});

it('finds nothing in a tree with no archives', function () {
    expect(ArchiveScan::directories([scanRoot()]))->toBe([]);
});

it('merges the archives found under several roots, listed once each', function () {
    $root = scanRoot();
    plantArchive($root.'/eng');

    expect(ArchiveScan::directories([$root, $root, $root.'/eng']))->toHaveCount(1);
});

it('names the directory it cannot scan', function () {
    expect(fn () => ArchiveScan::directories(['/tmp/nope-'.bin2hex(random_bytes(4))]))
        ->toThrow(RuntimeException::class, 'Unable to scan');
});

it('picks up an archive a real run just wrote', function () {
    $root = scanRoot();

    (new BatchRunner(new SlackEntryArchiver(fixtureClient())))->run([
        new BatchEntry('#eng-leadership', $root.'/eng-leadership'),
    ]);

    $found = ArchiveScan::directories([$root]);

    expect($found)->toHaveCount(1)
        ->and($found[0]->target)->toBe('C47JM9E9K')
        ->and($found[0]->label)->toBe('#eng-leadership');
});
