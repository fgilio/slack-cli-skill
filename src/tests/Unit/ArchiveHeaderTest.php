<?php

use App\Archive\ArchiveHeader;

function headerFile(string $contents): string
{
    $path = archiveDir().'/raw.md';
    file_put_contents($path, $contents);

    return $path;
}

it('reads back every field of the header it writes', function () {
    $header = new ArchiveHeader('#eng-leadership', 'Acme Books', 'Canal privado, 12 miembros.', 'Europe/London', '2026-08-01', '2026-08-05');

    $read = ArchiveHeader::read(headerFile($header->toMarkdown()."\n## 2026-08-01\n"));

    expect($read)->toEqual($header)
        ->and($read?->toMarkdown())->toBe($header->toMarkdown());
});

it('writes a workspace name holding a period back unchanged', function () {
    $markdown = (new ArchiveHeader('DM con Ana Pérez', 'Acme Inc. Team', 'Mensaje directo con Ana Pérez.', 'UTC', '2026-08-01', '2026-08-01'))
        ->toMarkdown();

    expect(ArchiveHeader::read(headerFile($markdown))?->toMarkdown())->toBe($markdown);
});

it('returns null for a file that does not open with an archive header', function (string $contents) {
    expect(ArchiveHeader::read(headerFile($contents)))->toBeNull();
})->with([
    'notes' => "# Notas\n\nalgo\n",
    'no timezone line' => "# #x — Dump completo (2026-08-01 a 2026-08-02)\n\nWorkspace: Acme Books. Grupo privado.\n",
    'empty' => '',
]);

it('returns null for a missing file', function () {
    expect(ArchiveHeader::read(archiveDir().'/raw.md'))->toBeNull();
});

it('splits a title line into its label and date range', function () {
    expect(ArchiveHeader::parseTitle("# #general — Dump completo (2025-08-25 a 2026-10-10)\n"))
        ->toBe(['#general', '2025-08-25', '2026-10-10'])
        ->and(ArchiveHeader::parseTitle('# #general'))->toBeNull();
});

it('reads a month file header, which carries no date range', function () {
    $header = new ArchiveHeader('DM con Ana Pérez', 'Acme Books', 'Mensaje directo con Ana Pérez.', 'Europe/London');
    $markdown = ArchiveHeader::monthTitle('DM con Ana Pérez', '2026-10')."\n".$header->restOfHeader()."## 2026-10-01\n";

    expect($markdown)->toStartWith("# DM con Ana Pérez — 2026-10\n\nWorkspace: Acme Books. Mensaje directo con Ana Pérez.\nHorarios en Europe/London .\n\n## 2026-10-01\n")
        ->and(ArchiveHeader::read(headerFile($markdown)))->toEqual($header);
});

it('reads the label from either title', function (string $line, ?string $label) {
    expect(ArchiveHeader::labelOf($line))->toBe($label);
})->with([
    'single raw.md' => ["# #general — Dump completo (2025-08-25 a 2026-10-10)\n", '#general'],
    'month file' => ["# #general — 2026-10\n", '#general'],
    'label holding a dash' => ['# DM con Ana — Pérez — 2026-10', 'DM con Ana — Pérez'],
    'notes' => ['# Notas', null],
]);

it('refuses to open a single raw.md without a date range', function () {
    expect(fn () => (new ArchiveHeader('#general', 'Acme', 'Grupo privado.', 'UTC'))->toMarkdown())
        ->toThrow(LogicException::class, 'carries no date range');
});
