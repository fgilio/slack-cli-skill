<?php

use App\Archive\MarkdownRenderer;
use App\Archive\SlackMarkup;
use Tests\Support\FakeNameResolver;

// 1785575640 is 2026-08-01 09:14:00 UTC. Every ts below hangs off that day.
const DAY_ONE_0914 = '1785575640.000100';
const DAY_ONE_0920 = '1785576000.000200';
const DAY_ONE_0930 = '1785576600.000300';
const DAY_TWO_1005 = '1785665100.000400';

function renderer(?string $lastRenderedDay = null): MarkdownRenderer
{
    $names = new FakeNameResolver(['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez']);

    return new MarkdownRenderer(new SlackMarkup($names), $names, new DateTimeZone('UTC'), $lastRenderedDay);
}

it('renders the archive header', function () {
    expect(renderer()->header('#eng-leadership', '2026-08-01', '2026-08-05', 'publica.la', 'Canal privado, 12 miembros.'))
        ->toBe(
            "# #eng-leadership — Dump completo (2026-08-01 a 2026-08-05)\n\n"
            ."Workspace: publica.la. Canal privado, 12 miembros.\n"
            ."Horarios en UTC .\n"
        );
});

it('opens a day header once per day that has messages', function () {
    $renderer = renderer();

    $first = $renderer->message(['ts' => DAY_ONE_0914, 'user' => 'U01', 'text' => 'buenas']);
    $same = $renderer->message(['ts' => DAY_ONE_0930, 'user' => 'U02', 'text' => 'buenas']);
    $next = $renderer->message(['ts' => DAY_TWO_1005, 'user' => 'U01', 'text' => 'seguimos']);

    expect($first)->toBe("\n## 2026-08-01\n\n**09:14 Franco Gilio:** buenas\n")
        ->and($same)->toBe("\n**09:30 Ana Pérez:** buenas\n")
        ->and($next)->toBe("\n## 2026-08-02\n\n**10:05 Franco Gilio:** seguimos\n");
});

it('continues under the day an earlier run left open', function () {
    $chunk = renderer('2026-08-01')->message(['ts' => DAY_ONE_0914, 'user' => 'U01', 'text' => 'hola']);

    expect($chunk)->toBe("\n**09:14 Franco Gilio:** hola\n");
});

it('nests thread replies as a blockquote under the parent', function () {
    $chunk = renderer()->message(
        ['ts' => DAY_ONE_0914, 'user' => 'U01', 'text' => '¿arrancamos?'],
        [['ts' => DAY_ONE_0920, 'user' => 'U02', 'text' => 'dale']],
    );

    expect($chunk)->toBe(
        "\n## 2026-08-01\n\n"
        ."**09:14 Franco Gilio:** ¿arrancamos?\n"
        ."  > **09:20 Ana Pérez:** dale\n"
    );
});

it('indents continuation lines by two spaces', function () {
    $chunk = renderer('2026-08-01')->message(['ts' => DAY_ONE_0914, 'user' => 'U01', 'text' => "uno\ndos\ntres"]);

    expect($chunk)->toBe("\n**09:14 Franco Gilio:** uno\n  dos\n  tres\n");
});

it('indents continuation lines of a reply inside the blockquote', function () {
    $chunk = renderer('2026-08-01')->message(
        ['ts' => DAY_ONE_0914, 'user' => 'U01', 'text' => 'ping'],
        [['ts' => DAY_ONE_0920, 'user' => 'U02', 'text' => "uno\ndos"]],
    );

    expect($chunk)->toBe(
        "\n**09:14 Franco Gilio:** ping\n"
        ."  > **09:20 Ana Pérez:** uno\n"
        ."  >   dos\n"
    );
});

it('decodes slack markup inside the body', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0914,
        'user' => 'U01',
        'text' => '<@U02> mirá <https://linear.app/x|el ticket> &amp; avisá <!here>',
    ]);

    expect($chunk)->toBe("\n**09:14 Franco Gilio:** @Ana Pérez mirá el ticket (https://linear.app/x) & avisá @here\n");
});

it('lists attachments after the message body', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0914,
        'user' => 'U01',
        'text' => 'acá va',
        'files' => [['name' => 'plan.pdf', 'mimetype' => 'application/pdf', 'size' => 1258291]],
    ]);

    expect($chunk)->toBe("\n**09:14 Franco Gilio:** acá va\n  [archivo adjunto: plan.pdf (application/pdf - 1.2 MB)]\n");
});

it('puts a lone attachment on the message line', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0914,
        'user' => 'U01',
        'text' => '',
        'files' => [['name' => 'foto.png', 'mimetype' => 'image/png', 'size' => 400]],
    ]);

    expect($chunk)->toBe("\n**09:14 Franco Gilio:** [archivo adjunto: foto.png (image/png - 400 B)]\n");
});

it('marks deleted messages', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0914,
        'user' => 'U01',
        'subtype' => 'tombstone',
        'text' => 'This message was deleted.',
    ]);

    expect($chunk)->toBe("\n**09:14 Franco Gilio:** [mensaje eliminado]\n");
});

it('names bot messages by their bot profile', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0914,
        'bot_id' => 'B01',
        'bot_profile' => ['name' => 'Linear'],
        'text' => 'ENG-12 movido a Done',
    ]);

    expect($chunk)->toBe("\n**09:14 Linear:** ENG-12 movido a Done\n");
});
