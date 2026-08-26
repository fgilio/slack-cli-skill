<?php

use App\Archive\BatchEntry;
use App\Archive\BatchManifest;

function manifest(array $entries): BatchManifest
{
    return BatchManifest::fromJson((string) json_encode($entries), 'test.json');
}

function manifestFile(string $contents): string
{
    $path = sys_get_temp_dir().'/slack-cli-manifest-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($path, $contents);

    return $path;
}

it('reads every field of an entry', function () {
    $entries = manifest([[
        'target' => '@gparra',
        'out' => '/tmp/gonza',
        'after' => '2025-08-25',
        'before' => '2026-01-31',
        'no_threads' => true,
    ]])->entries;

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->target)->toBe('@gparra')
        ->and($entries[0]->outDir)->toBe('/tmp/gonza')
        ->and($entries[0]->after)->toBe('2025-08-25')
        ->and($entries[0]->before)->toBe('2026-01-31')
        ->and($entries[0]->noThreads)->toBeTrue();
});

it('defaults an entry to the whole conversation with threads', function () {
    $entry = manifest([['target' => 'engineering-team', 'out' => '/tmp/eng']])->entries[0];

    expect($entry->after)->toBeNull()
        ->and($entry->before)->toBeNull()
        ->and($entry->noThreads)->toBeFalse();
});

it('expands a ~ in an out path', function () {
    $entry = manifest([['target' => '@gparra', 'out' => '~/pla/team/People/Gonza/Slack-DM']])->entries[0];

    expect($entry->outDir)->toBe(getenv('HOME').'/pla/team/People/Gonza/Slack-DM')
        ->and($entry->label())->toBe('Slack-DM');
});

it('trims a trailing slash off an out path', function () {
    expect(manifest([['target' => 'eng', 'out' => '/tmp/eng/']])->entries[0]->outDir)->toBe('/tmp/eng');
});

it('reads a manifest from disk', function () {
    $path = manifestFile('[{"target": "eng", "out": "/tmp/eng"}]');

    expect(BatchManifest::fromFile($path)->entries)->toHaveCount(1);
});

it('names the manifest it cannot find', function () {
    expect(fn () => BatchManifest::fromFile('/tmp/nope-'.bin2hex(random_bytes(4)).'.json'))
        ->toThrow(RuntimeException::class, 'Unable to read the manifest at');
});

it('rejects a manifest that is not valid JSON', function () {
    expect(fn () => BatchManifest::fromJson('{not json', 'test.json'))
        ->toThrow(RuntimeException::class, 'is not valid JSON');
});

it('rejects a manifest that is an object rather than an array', function () {
    expect(fn () => BatchManifest::fromJson('{"target": "eng"}', 'test.json'))
        ->toThrow(RuntimeException::class, 'must be a JSON array of entries');
});

it('rejects an empty manifest', function () {
    expect(fn () => BatchManifest::fromJson('[]', 'test.json'))
        ->toThrow(RuntimeException::class, 'is empty');
});

it('rejects an entry that is not an object', function () {
    expect(fn () => manifest([['eng', '/tmp/eng']]))
        ->toThrow(RuntimeException::class, "Entry 1 must be a JSON object with a 'target' and an 'out'");
});

it('names the entry missing a target', function () {
    expect(fn () => manifest([['out' => '/tmp/eng']]))
        ->toThrow(RuntimeException::class, "Entry 1 needs a 'target'");
});

it('names the entry missing an out', function () {
    expect(fn () => manifest([['target' => 'engineering-team']]))
        ->toThrow(RuntimeException::class, "Entry 1 (engineering-team) needs an 'out'");
});

it('rejects a relative out path', function () {
    expect(fn () => manifest([['target' => 'eng', 'out' => './eng']]))
        ->toThrow(RuntimeException::class, 'which is a relative path');
});

it('rejects a date that is not YYYY-MM-DD', function () {
    expect(fn () => manifest([['target' => 'eng', 'out' => '/tmp/eng', 'after' => '25/08/2025']]))
        ->toThrow(RuntimeException::class, "has an invalid 'after'");
});

it('rejects a day that never happened', function () {
    expect(fn () => manifest([['target' => 'eng', 'out' => '/tmp/eng', 'before' => '2025-02-30']]))
        ->toThrow(RuntimeException::class, "has an invalid 'before'");
});

it('rejects a no_threads that is not a boolean', function () {
    expect(fn () => manifest([['target' => 'eng', 'out' => '/tmp/eng', 'no_threads' => 'yes']]))
        ->toThrow(RuntimeException::class, "has a 'no_threads' that is not true or false");
});

it('rejects an unknown field so a typo never passes silently', function () {
    expect(fn () => manifest([['target' => 'eng', 'out' => '/tmp/eng', 'afer' => '2025-08-25']]))
        ->toThrow(RuntimeException::class, "has an unknown field 'afer'");
});

it('rejects two entries writing into the same directory', function () {
    expect(fn () => manifest([
        ['target' => 'eng', 'out' => '/tmp/eng'],
        ['target' => 'engineering-team', 'out' => '/tmp/eng'],
    ]))->toThrow(RuntimeException::class, 'which entry 1 already claims');
});

it('reports every broken entry at once', function () {
    expect(fn () => manifest([
        ['out' => '/tmp/eng'],
        ['target' => 'eng', 'out' => 'relative'],
        ['target' => 'ops', 'out' => '/tmp/ops', 'after' => 'today'],
    ]))->toThrow(RuntimeException::class, 'has 3 problems');
});

it('keeps every entry when no glob is given', function () {
    $manifest = manifest([
        ['target' => '@gparra', 'out' => '/tmp/Gonza/Slack-DM'],
        ['target' => 'engineering-team', 'out' => '/tmp/eng'],
    ]);

    expect($manifest->select(null))->toHaveCount(2)
        ->and($manifest->select(''))->toHaveCount(2);
});

it('filters on the target', function () {
    $selected = manifest([
        ['target' => '@gparra', 'out' => '/tmp/Gonza/Slack-DM'],
        ['target' => 'engineering-team', 'out' => '/tmp/eng'],
    ])->select('eng*');

    expect(array_map(fn (BatchEntry $entry) => $entry->target, $selected))->toBe(['engineering-team']);
});

it('filters on the output directory name', function () {
    $selected = manifest([
        ['target' => '@gparra', 'out' => '/tmp/Gonza/Slack-DM'],
        ['target' => 'engineering-team', 'out' => '/tmp/eng'],
    ])->select('Slack-*');

    expect(array_map(fn (BatchEntry $entry) => $entry->target, $selected))->toBe(['@gparra']);
});

it('treats a bare word as a match anywhere', function () {
    $selected = manifest([
        ['target' => '@gparra', 'out' => '/tmp/Gonza/Slack-DM'],
        ['target' => 'engineering-team', 'out' => '/tmp/eng'],
    ])->select('gparra');

    expect($selected)->toHaveCount(1)
        ->and($selected[0]->target)->toBe('@gparra');
});

it('ignores case in a glob', function () {
    expect(manifest([['target' => 'Engineering-Team', 'out' => '/tmp/eng']])->select('engineering*'))
        ->toHaveCount(1);
});

it('lists the available targets when a glob matches nothing', function () {
    expect(fn () => manifest([
        ['target' => '@gparra', 'out' => '/tmp/Gonza/Slack-DM'],
        ['target' => 'engineering-team', 'out' => '/tmp/eng'],
    ])->select('design'))->toThrow(
        RuntimeException::class,
        'No manifest entry matches --only=design. Available entries: @gparra (Slack-DM), engineering-team (eng)',
    );
});
