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

it('renders a message shared without a comment as a quoted preview', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0930,
        'user' => 'U01',
        'text' => '',
        'attachments' => [[
            'is_share' => true,
            'is_msg_unfurl' => true,
            'ts' => DAY_ONE_0914,
            'author_id' => 'U02',
            'author_name' => 'Ana Pérez',
            'from_url' => 'https://acme.slack.com/archives/C01/p1785575640000100',
            'text' => "Les cuento cómo quedó el release\n\n• <@U01> revisa el changelog",
        ]],
    ]);

    expect($chunk)->toBe(
        "\n**09:30 Franco Gilio:** [mensaje compartido de Ana Pérez, 2026-08-01 09:14]\n"
        ."  > Les cuento cómo quedó el release\n"
        ."  >\n"
        ."  > • @Franco Gilio revisa el changelog\n"
    );
});

it('puts a shared preview after the comment and the files that came with it', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0930,
        'user' => 'U01',
        'text' => 'mirá esto',
        'files' => [['name' => 'foto.png', 'mimetype' => 'image/png', 'size' => 400]],
        'attachments' => [[
            'is_msg_unfurl' => true,
            'ts' => DAY_ONE_0914,
            'author_subname' => 'Ana Pérez',
            'text' => 'el plan',
            'files' => [['name' => 'plan.pdf', 'mimetype' => 'application/pdf', 'size' => 2048]],
        ]],
    ]);

    expect($chunk)->toBe(
        "\n**09:30 Franco Gilio:** mirá esto\n"
        ."  [archivo adjunto: foto.png (image/png - 400 B)]\n"
        ."  [mensaje compartido de Ana Pérez, 2026-08-01 09:14]\n"
        ."  > el plan\n"
        ."  > [archivo adjunto: plan.pdf (application/pdf - 2 KB)]\n"
    );
});

it('nests a preview shared inside a thread reply', function () {
    $chunk = renderer('2026-08-01')->message(
        ['ts' => DAY_ONE_0914, 'user' => 'U01', 'text' => '¿lo vieron?'],
        [[
            'ts' => DAY_ONE_0920,
            'user' => 'U02',
            'text' => '',
            'attachments' => [['is_share' => true, 'ts' => DAY_ONE_0914, 'author_id' => 'U01', 'text' => "uno\ndos"]],
        ]],
    );

    expect($chunk)->toBe(
        "\n**09:14 Franco Gilio:** ¿lo vieron?\n"
        ."  > **09:20 Ana Pérez:** [mensaje compartido de Franco Gilio, 2026-08-01 09:14]\n"
        ."  >   > uno\n"
        ."  >   > dos\n"
    );
});

it('leads a shared bot message with its title and names no author it lacks', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0930,
        'user' => 'U01',
        'text' => '',
        'attachments' => [['is_share' => true, 'title' => 'Charge failed', 'text' => '$10.00 USD failed charge']],
    ]);

    expect($chunk)->toBe(
        "\n**09:30 Franco Gilio:** [mensaje compartido de desconocido]\n"
        ."  > Charge failed\n"
        ."  > \$10.00 USD failed charge\n"
    );
});

it('renders one preview per shared message', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0930,
        'user' => 'U01',
        'text' => 'dos hilos',
        'attachments' => [
            ['is_msg_unfurl' => true, 'ts' => DAY_ONE_0914, 'author_name' => 'Ana Pérez', 'text' => 'primero'],
            ['is_msg_unfurl' => true, 'ts' => DAY_ONE_0920, 'author_name' => 'Franco Gilio', 'text' => 'segundo'],
        ],
    ]);

    expect($chunk)->toBe(
        "\n**09:30 Franco Gilio:** dos hilos\n"
        ."  [mensaje compartido de Ana Pérez, 2026-08-01 09:14]\n"
        ."  > primero\n"
        ."  [mensaje compartido de Franco Gilio, 2026-08-01 09:20]\n"
        ."  > segundo\n"
    );
});

it('leaves website link previews out', function () {
    $chunk = renderer('2026-08-01')->message([
        'ts' => DAY_ONE_0914,
        'user' => 'U01',
        'text' => '<https://example.com/post>',
        'attachments' => [['title' => 'Example post', 'text' => 'A preview of the page', 'from_url' => 'https://example.com/post']],
    ]);

    expect($chunk)->toBe("\n**09:14 Franco Gilio:** https://example.com/post\n");
});

it('renders shared previews in the archive timezone', function () {
    $names = new FakeNameResolver;
    $renderer = new MarkdownRenderer(new SlackMarkup($names), $names, new DateTimeZone('America/Argentina/Buenos_Aires'), '2026-08-01');

    $chunk = $renderer->message([
        'ts' => DAY_ONE_0930,
        'user' => 'U01',
        'text' => '',
        'attachments' => [['is_share' => true, 'ts' => DAY_ONE_0914, 'author_name' => 'Ana Pérez', 'text' => 'hola']],
    ]);

    expect($chunk)->toContain('[mensaje compartido de Ana Pérez, 2026-08-01 06:14]');
});
