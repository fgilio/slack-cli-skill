<?php

use App\Archive\Jsonl;

function tempArchiveDir(): string
{
    $dir = sys_get_temp_dir().'/slack-cli-archive-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    register_shutdown_function(function () use ($dir) {
        foreach (glob($dir.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($dir);
    });

    return $dir;
}

/**
 * @param  list<array<string, mixed>>  $messages
 */
function writePage(string $dir, int $index, array $messages): string
{
    $path = sprintf('%s/page-%06d.jsonl', $dir, $index);
    Jsonl::writeAtomic($path, $messages);

    return $path;
}

it('replays newest-first pages into one oldest-first file', function () {
    $dir = tempArchiveDir();

    $pages = [
        writePage($dir, 0, [['ts' => '30'], ['ts' => '40']]),
        writePage($dir, 1, [['ts' => '10'], ['ts' => '20']]),
    ];

    $written = Jsonl::concatReverse($pages, $dir.'/history.jsonl');

    $order = array_column(iterator_to_array(Jsonl::read($dir.'/history.jsonl')), 'ts');

    expect($written)->toBe(4)
        ->and($order)->toBe(['10', '20', '30', '40']);
});

it('skips page files a run never got to write', function () {
    $dir = tempArchiveDir();

    $pages = [writePage($dir, 0, [['ts' => '30']]), $dir.'/page-000001.jsonl'];

    expect(Jsonl::concatReverse($pages, $dir.'/history.jsonl'))->toBe(1);
});

it('leaves no partial file behind when a write finishes', function () {
    $dir = tempArchiveDir();

    writePage($dir, 0, [['ts' => '10']]);

    expect(glob($dir.'/*.part'))->toBe([]);
});

it('finds the newest channel message and ignores thread replies', function () {
    $dir = tempArchiveDir();
    $path = $dir.'/messages.jsonl';

    Jsonl::writeAtomic($path, [
        ['ts' => '100.000100'],
        ['ts' => '200.000100', 'thread_ts' => '200.000100', 'reply_count' => 2],
        ['ts' => '900.000100', 'thread_ts' => '200.000100'],
        ['ts' => '300.000100'],
    ]);

    expect(Jsonl::latestChannelTs($path))->toBe('300.000100');
});

it('reports no newest message for an archive that does not exist yet', function () {
    expect(Jsonl::latestChannelTs(tempArchiveDir().'/missing.jsonl'))->toBeNull();
});

it('reports the first and last timestamps of an ordered file', function () {
    $dir = tempArchiveDir();
    $path = $dir.'/history.jsonl';

    Jsonl::writeAtomic($path, [['ts' => '10.5'], ['ts' => '20.5'], ['ts' => '30.5']]);

    expect(Jsonl::boundingTimestamps($path))->toBe(['10.5', '30.5']);
});

it('refuses to read a corrupt archive file', function () {
    $path = tempArchiveDir().'/broken.jsonl';
    file_put_contents($path, "{\"ts\":\"1\"}\nnot json\n");

    expect(fn () => iterator_to_array(Jsonl::read($path)))
        ->toThrow(RuntimeException::class, 'not valid JSON');
});

it('preserves unicode and slashes when encoding', function () {
    expect(Jsonl::encode(['text' => 'mañana https://x.test/a']))
        ->toBe('{"text":"mañana https://x.test/a"}');
});
