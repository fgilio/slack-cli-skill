<?php

use App\Archive\ArchiveCheckpoint;
use App\Archive\ArchiveHeader;
use App\Archive\ArchiveRequest;
use App\Archive\ChannelArchiver;
use App\Archive\MonthSplitter;
use App\Archive\RawMonths;
use Tests\Support\FakeSlackClient;

const AUGUST_HEADER = "Workspace: publica.la. Canal privado, 12 miembros.\nHorarios en UTC .\n";

function refreshWith(FakeSlackClient $client, string $dir, bool $resume = false, ?Closure $progress = null): void
{
    (new ChannelArchiver($client))->archive(new ArchiveRequest(
        channelId: 'C47JM9E9K',
        outDir: $dir,
        resume: $resume,
        sinceLast: true,
    ), $progress);
}

function octoberPages(): array
{
    return [[['ts' => TS_OCTOBER, 'user' => 'U01', 'text' => 'y octubre']], ...crossMonthPages()];
}

/**
 * What the splitter writes for a stream, keyed by month.
 *
 * @return array<string, string>
 */
function splitStream(string $stream, ?Closure $before = null): array
{
    $dir = archiveDir();
    $before?->__invoke($dir);

    $splitter = is_file($dir.'/raw/2026-08.md')
        ? MonthSplitter::continuing($dir.'/raw', '2026-08')
        : MonthSplitter::fresh($dir.'/raw', new ArchiveHeader('#x', 'publica.la', 'Canal privado, 12 miembros.', 'UTC'));

    foreach (str_split($stream, 7) as $chunk) {
        $splitter->write($chunk);
    }

    $splitter->close();

    return rawMonths($dir);
}

it('splits the days of an archive into one file per month', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(crossMonthPages()), $dir);

    $months = rawMonths($dir);

    expect(array_keys($months))->toBe(['2026-08', '2026-09'])
        ->and($months['2026-08'])->toStartWith("# #eng-leadership — 2026-08\n\n".AUGUST_HEADER."\n## 2026-08-01\n")
        ->and($months['2026-08'])->toEndWith("**10:05 Franco Gilio:** seguimos mañana\n")
        ->and($months['2026-09'])->toBe(
            "# #eng-leadership — 2026-09\n\n".AUGUST_HEADER
            ."\n## 2026-09-01\n"
            ."\n**09:14 Ana Pérez:** arranca septiembre\n"
        );
});

it('gives back the single raw.md when the months are joined', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(crossMonthPages()), $dir);

    $legacy = toLegacyLayout($dir);

    expect($legacy)->toContain("**10:05 Franco Gilio:** seguimos mañana\n\n## 2026-09-01\n\n**09:14 Ana Pérez:** arranca septiembre\n")
        ->and(renderTester()->execute(['dir' => $dir, '--check' => true]))->toBe(0);
});

it('trims the blank lines that trail a month and ends every file in one newline', function () {
    $months = splitStream("\n## 2026-08-01\n\nuno\n\n\n## 2026-09-01\n\ndos\n\n\n");

    expect($months)->toBe([
        '2026-08' => "# #x — 2026-08\n\n".AUGUST_HEADER."\n## 2026-08-01\n\nuno\n",
        '2026-09' => "# #x — 2026-09\n\n".AUGUST_HEADER."\n## 2026-09-01\n\ndos\n",
    ]);
});

it('keeps a line that only looks like a day header with the day it sits in', function () {
    $months = splitStream("\n## 2026-08-01\n\n**09:14 Ana:** mirá\n  ## 2026-09-01\n  > ## 2026-09-02\n");

    expect(array_keys($months))->toBe(['2026-08']);
});

it('ends a stream cut mid-line with a newline', function () {
    expect(splitStream("\n## 2026-08-01\n\nsin final")['2026-08'])->toEndWith("\nsin final\n");
});

it('refuses a stream whose months step backwards', function () {
    splitStream("\n## 2026-09-01\n\nuno\n\n## 2026-08-31\n\ndos\n");
})->throws(RuntimeException::class, 'steps back from 2026-09 to 2026-08');

it('refuses text before the first day header of a fresh render', function () {
    splitStream("\nhuérfano\n");
})->throws(RuntimeException::class, 'holds text before its first day header');

it('appends to a month file that lost its final newline on a new line', function () {
    $months = splitStream("\n**10:00 Ana:** sigue\n", function (string $dir) {
        mkdir($dir.'/raw');
        file_put_contents($dir.'/raw/2026-08.md', "# #x — 2026-08\n\n".AUGUST_HEADER."\n## 2026-08-01\n\n**09:00 Ana:** hola");
    });

    expect($months['2026-08'])->toEndWith("**09:00 Ana:** hola\n\n**10:00 Ana:** sigue\n");
});

it('appends a refresh to the open month the way raw.md took it', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    $before = rawMonths($dir)['2026-08'];

    refreshWith(sharedClient([[['ts' => TS_LATER, 'user' => 'U02', 'text' => 'una más']]]), $dir);

    expect(rawMonths($dir))->toBe(['2026-08' => $before."\n**11:05 Ana Pérez:** una más\n"]);
});

it('opens a new month with the header of the newest month and leaves older months alone', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(crossMonthPages()), $dir);

    // Proves the header is copied from disk rather than fetched again.
    $august = rawMonths($dir)['2026-08'];
    $september = str_replace('Canal privado, 12 miembros.', 'Canal privado, 9 miembros.', rawMonths($dir)['2026-09']);
    file_put_contents($dir.'/raw/2026-09.md', $september);
    touch($dir.'/raw/2026-08.md', 1_000_000);

    refreshWith(sharedClient(octoberPages()), $dir);

    clearstatcache();

    expect(array_keys(rawMonths($dir)))->toBe(['2026-08', '2026-09', '2026-10'])
        ->and(rawMonths($dir)['2026-08'])->toBe($august)
        ->and(filemtime($dir.'/raw/2026-08.md'))->toBe(1_000_000)
        ->and(rawMonths($dir)['2026-09'])->toBe($september)
        ->and(rawMonths($dir)['2026-10'])->toBe(
            "# #eng-leadership — 2026-10\n\nWorkspace: publica.la. Canal privado, 9 miembros.\nHorarios en UTC .\n"
            ."\n## 2026-10-01\n"
            ."\n**09:14 Franco Gilio:** y octubre\n"
        );
});

it('leaves raw/ untouched when a refresh brings nothing', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    $before = rawMonths($dir);
    touch($dir.'/raw/2026-08.md', 1_000_000);

    refreshWith(sharedClient(), $dir);

    clearstatcache();

    expect(rawMonths($dir))->toBe($before)
        ->and(filemtime($dir.'/raw/2026-08.md'))->toBe(1_000_000);
});

it('writes the same months whether the refresh ran once or died after writing them', function () {
    $once = archiveDir();
    archivedWith(sharedClient(), $once);
    refreshWith(sharedClient(octoberPages()), $once);

    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    // Dies after every month reached disk, before the run could finish.
    $crash = function (string $line) {
        throw_if(str_starts_with($line, 'Wrote '), RuntimeException::class, 'killed');
    };

    expect(fn () => refreshWith(sharedClient(octoberPages()), $dir, progress: $crash))->toThrow(RuntimeException::class, 'killed');
    expect(array_keys(rawMonths($dir)))->toBe(['2026-08', '2026-09', '2026-10']);

    refreshWith(sharedClient(octoberPages()), $dir, resume: true);

    expect(rawCopy($dir))->toBe(rawCopy($once))
        ->and(archivedTimestamps($dir))->toBe(archivedTimestamps($once));
});

it('cuts raw/ back to where the refresh started when it resumes a half-written render', function () {
    $once = archiveDir();
    archivedWith(sharedClient(), $once);
    refreshWith(sharedClient(octoberPages()), $once);

    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    $mark = filesize($dir.'/raw/2026-08.md');

    $crash = function (string $line) {
        throw_if(str_starts_with($line, 'Fetched replies'), RuntimeException::class, 'killed');
    };

    expect(fn () => refreshWith(sharedClient(octoberPages()), $dir, progress: $crash))->toThrow(RuntimeException::class, 'killed');
    expect(ArchiveCheckpoint::load($dir.'/.archive-checkpoint.json')?->monthBytes)->toBe(['2026-08' => $mark]);

    // What a render killed part way leaves: a tail on the open month, a
    // finished new month, and a new month still being written.
    file_put_contents($dir.'/raw/2026-08.md', "\n**11:00 Ana Pérez:** a medi", FILE_APPEND);
    file_put_contents($dir.'/raw/2026-09.md', "# #eng-leadership — 2026-09\n\nsobras\n");
    file_put_contents($dir.'/raw/2026-10.md.part', '# sobr');
    file_put_contents($dir.'/messages.jsonl', '{"ts":"1788254040.000600","tor', FILE_APPEND);

    refreshWith(sharedClient(octoberPages()), $dir, resume: true);

    expect(rawCopy($dir))->toBe(rawCopy($once))
        ->and(glob($dir.'/raw/*.part'))->toBe([])
        ->and(archivedTimestamps($dir))->toBe(archivedTimestamps($once));
});

it('refuses to resume when a month lost bytes the run recorded', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);

    $crash = function (string $line) {
        throw_if(str_starts_with($line, 'Fetched replies'), RuntimeException::class, 'killed');
    };

    expect(fn () => refreshWith(sharedClient(octoberPages()), $dir, progress: $crash))->toThrow(RuntimeException::class, 'killed');

    file_put_contents($dir.'/raw/2026-08.md', '# cut');

    expect(fn () => refreshWith(sharedClient(octoberPages()), $dir, resume: true))
        ->toThrow(RuntimeException::class, 'lost bytes since the interrupted run started');
});

it('checks a single raw.md against a render of the same single file', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(crossMonthPages()), $dir);
    $legacy = toLegacyLayout($dir);
    $tester = renderTester();

    expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(0)
        ->and($tester->getDisplay())->toContain('raw.md in '.$dir.' matches its messages.jsonl');

    $edited = str_replace('arranca septiembre', 'arranca octubre', $legacy);
    $line = array_key_first(preg_grep('/arranca octubre/', explode("\n", $edited))) + 1;
    file_put_contents($dir.'/raw.md', $edited);

    expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(1)
        ->and($tester->getDisplay())->toContain("raw.md in {$dir} differs from a render of its messages.jsonl: raw.md:{$line}.")
        ->and(is_dir($dir.'/raw'))->toBeFalse();
});

it('moves a single raw.md to raw/ when rendering it', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(crossMonthPages()), $dir);
    $months = rawMonths($dir);
    toLegacyLayout($dir);

    $tester = renderTester();

    expect($tester->execute(['dir' => $dir]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Moved raw.md to raw/, one file per month')
        ->and(rawMonths($dir))->toBe($months)
        ->and(is_file($dir.'/raw.md'))->toBeFalse()
        ->and(is_dir($dir.'/.raw-render'))->toBeFalse()
        ->and($tester->execute(['dir' => $dir, '--check' => true]))->toBe(0);
});

it('drops the months no message falls in anymore when rendering', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    copy($dir.'/raw/2026-08.md', $dir.'/raw/2026-07.md');
    file_put_contents($dir.'/raw.md', 'stray');

    expect(renderTester()->execute(['dir' => $dir]))->toBe(0)
        ->and(array_keys(rawMonths($dir)))->toBe(['2026-08'])
        ->and(is_file($dir.'/raw.md'))->toBeFalse();
});

it('keeps refreshing a single raw.md until it is rendered', function () {
    $dir = archiveDir();
    archivedWith(sharedClient(), $dir);
    $legacy = toLegacyLayout($dir);

    refreshWith(sharedClient(crossMonthPages()), $dir);

    expect(is_dir($dir.'/raw'))->toBeFalse()
        ->and(file_get_contents($dir.'/raw.md'))->toBe(
            str_replace('a 2026-08-02)', 'a 2026-09-01)', $legacy)
            ."\n## 2026-09-01\n\n**09:14 Ana Pérez:** arranca septiembre\n"
        );
});

describe('an archive that holds no messages', function () {
    /**
     * What a migration leaves of an archive whose raw.md was a header and
     * nothing else: messages.jsonl, the metadata, and no month files.
     */
    function emptyArchive(): string
    {
        $dir = archiveDir();
        touch($dir.'/messages.jsonl');
        file_put_contents($dir.'/.archive-meta.json', json_encode(['version' => 1, 'channel_id' => 'C47JM9E9K', 'channel' => '#eng-leadership']));

        return $dir;
    }

    it('renders to nothing', function () {
        $dir = emptyArchive();
        $tester = renderTester();

        expect($tester->execute(['dir' => $dir]))->toBe(0)
            ->and($tester->getDisplay())->toContain('0 messages')
            ->and(is_dir($dir.'/raw'))->toBeFalse()
            ->and(is_dir($dir.'/.raw-render'))->toBeFalse();
    });

    it('matches a render of no months', function () {
        $dir = emptyArchive();
        $tester = renderTester();

        expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(0);

        mkdir($dir.'/raw');
        file_put_contents($dir.'/raw/2026-08.md', "# #eng-leadership — 2026-08\n");

        expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(1)
            ->and($tester->getDisplay())->toContain(': unexpected raw/2026-08.md.');
    });

    it('moves a header-only raw.md to no month files at all', function () {
        $dir = emptyArchive();
        file_put_contents($dir.'/raw.md', "# #eng-leadership — Dump completo (2026-08-01 a 2026-08-01)\n\n".AUGUST_HEADER);
        $tester = renderTester();

        expect($tester->execute(['dir' => $dir, '--check' => true]))->toBe(0)
            ->and($tester->execute(['dir' => $dir]))->toBe(0)
            ->and(is_file($dir.'/raw.md'))->toBeFalse()
            ->and(is_dir($dir.'/raw'))->toBeFalse()
            ->and($tester->execute(['dir' => $dir, '--check' => true]))->toBe(0);
    });

    it('writes its first messages the way a new archive would', function () {
        $fresh = archiveDir();
        archivedWith(sharedClient(), $fresh);

        $dir = emptyArchive();
        refreshWith(sharedClient(), $dir);

        expect(rawMonths($dir))->toBe(rawMonths($fresh))
            ->and(renderTester()->execute(['dir' => $dir, '--check' => true]))->toBe(0);
    });
});

it('names the month files of a directory oldest first and skips everything else', function () {
    $dir = archiveDir();
    mkdir($dir.'/raw');

    foreach (['2026-10.md', '2025-12.md', '2026-01.md.part', 'notas.md', '2026-1.md'] as $name) {
        touch($dir.'/raw/'.$name);
    }

    expect(RawMonths::in($dir.'/raw'))->toBe(['2025-12', '2026-10'])
        ->and(RawMonths::newestIn($dir.'/raw'))->toBe('2026-10')
        ->and(RawMonths::in($dir.'/missing'))->toBe([]);
});
