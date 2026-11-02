<?php

use App\Archive\ArchiveRequest;
use App\Archive\MarkdownRebuilder;
use App\Archive\SlackNameResolver;
use Tests\Support\FakeSlackClient;
use Tests\Support\ShortWriteStream;

it('writes the same raw/ the archive run wrote', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir);

    file_put_contents($dir.'/raw/2026-08.md', "# #eng-leadership — 2026-08\n\nWorkspace: publica.la. Canal privado, 12 miembros.\nHorarios en UTC .\n\n## 2026-08-01\n\nstale body\n");

    expect(renderTester()->execute(['dir' => $dir]))->toBe(0)
        ->and(rawCopy($dir))->toBe($archived)
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
        ->and(rawCopy($dir))->toBe($extended)
        ->and(substr_count($extended, '## 2026-08-02'))->toBe(1);
});

it('keeps a reply sent to the channel on its own line when the run skipped threads', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir, threads: false);

    expect(renderTester()->execute(['dir' => $dir]))->toBe(0)
        ->and(rawCopy($dir))->toBe($archived)
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
        ->and(rawCopy($dir))->toBe($extended);
})->with([
    'threads, then --no-threads' => [true, false],
    '--no-threads, then threads' => [false, true],
]);

it('nests replies when the metadata predates the thread settings', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir);

    file_put_contents($dir.'/.archive-meta.json', json_encode(['version' => 1, 'channel_id' => 'C47JM9E9K', 'channel' => '#eng-leadership']));

    renderTester()->execute(['dir' => $dir]);

    expect(rawCopy($dir))->toBe($archived);
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

it('checks raw/ against a fresh render and writes nothing', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    $tester = renderTester();

    expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(0)
        ->and($tester->getDisplay())->toContain('raw/ in '.$dir.' matches its messages.jsonl');

    $edited = str_replace('seguimos mañana', 'seguimos el lunes', rawMonths($dir)['2026-08']);
    $line = array_key_first(preg_grep('/seguimos el lunes/', explode("\n", $edited))) + 1;
    file_put_contents($dir.'/raw/2026-08.md', $edited);

    expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(1)
        ->and($tester->getDisplay())->toContain("differs from a render of its messages.jsonl: raw/2026-08.md:{$line}.")
        ->and(rawMonths($dir))->toBe(['2026-08' => $edited])
        ->and(glob($dir.'/raw/*.part'))->toBe([])
        ->and(is_dir($dir.'/.raw-render'))->toBeFalse();
});

it('reports a month missing from raw/', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(crossMonthPages()), $dir);
    unlink($dir.'/raw/2026-09.md');

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(1)
        ->and($tester->getDisplay())->toContain(': missing raw/2026-09.md.');
});

it('reports a month in raw/ that no message falls in', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    copy($dir.'/raw/2026-08.md', $dir.'/raw/2026-07.md');

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(1)
        ->and($tester->getDisplay())->toContain(': unexpected raw/2026-07.md.');
});

it('reports a check as JSON', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    $tester = renderTester();

    $tester->execute(['dir' => $dir, '--check' => true, '--json' => true]);
    expect(json_decode($tester->getDisplay(), true)['data'])->toBe(['matches' => true]);

    file_put_contents($dir.'/raw/2026-08.md', "stale\n", FILE_APPEND);

    expect($tester->execute(['dir' => $dir, '--check' => true, '--json' => true]))->toBe(1);
});

it('describes a difference by file and line', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    file_put_contents($dir.'/raw/2026-08.md', "stale\n", FILE_APPEND);
    $lines = substr_count(rawMonths($dir)['2026-08'], "\n");

    expect((new MarkdownRebuilder(new SlackNameResolver(sharedClient())))->firstDifference($dir)?->toArray())->toBe([
        'difference' => "raw/2026-08.md:{$lines}",
        'file' => 'raw/2026-08.md',
        'line' => $lines,
    ]);
});

it('leaves raw/ alone when the render cannot be written in full', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir);
    $rebuilder = new MarkdownRebuilder(new SlackNameResolver(sharedClient()));

    stream_wrapper_register(ShortWriteStream::SCHEME, ShortWriteStream::class);

    try {
        expect(fn () => $rebuilder->rebuild(ShortWriteStream::SCHEME.'://'.$dir))
            ->toThrow(RuntimeException::class, 'Unable to write the render');
    } finally {
        stream_wrapper_unregister(ShortWriteStream::SCHEME);
    }

    expect(rawCopy($dir))->toBe($archived)
        ->and(glob($dir.'/raw/*.part'))->toBe([])
        ->and(is_dir($dir.'/.raw-render'))->toBeFalse();
});

it('refuses a directory holding an interrupted archive run', function () {
    $dir = archiveDir();
    $archived = archivedWith(sharedClient(), $dir);

    file_put_contents(ArchiveRequest::checkpointPathIn($dir), '{}');

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir]))->toBe(1)
        ->and($tester->getDisplay())->toContain('holds an interrupted archive run')
        ->and(rawCopy($dir))->toBe($archived);
});

it('refuses a month file without an archive header', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    file_put_contents($dir.'/raw/2026-08.md', "# Notas\n");

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Unable to read the archive header at the top of '.$dir.'/raw/2026-08.md')
        ->and(rawMonths($dir))->toBe(['2026-08' => "# Notas\n"])
        ->and(is_dir($dir.'/.raw-render'))->toBeFalse();
});

it('refuses a single raw.md without an archive header', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    toLegacyLayout($dir);

    file_put_contents($dir.'/raw.md', "# Notas\n");

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Unable to read the archive header at the top of '.$dir.'/raw.md')
        ->and(file_get_contents($dir.'/raw.md'))->toBe("# Notas\n")
        ->and(is_dir($dir.'/raw'))->toBeFalse();
});

it('refuses messages with no header to render them under', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    unlink($dir.'/raw/2026-08.md');

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir]))->toBe(1)
        ->and($tester->getDisplay())->toContain('Unable to read the archive header');
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
