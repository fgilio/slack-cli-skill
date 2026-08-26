<?php

use App\Archive\SlackMarkup;
use Tests\Support\FakeNameResolver;

function markup(): SlackMarkup
{
    return new SlackMarkup(new FakeNameResolver(
        users: ['U01' => 'Franco Gilio', 'U02' => 'Ana Pérez'],
        channels: ['C99' => 'eng-leadership'],
    ));
}

it('resolves user mentions to display names', function () {
    expect(markup()->decode('hola <@U01> y <@U02>'))
        ->toBe('hola @Franco Gilio y @Ana Pérez');
});

it('keeps the label Slack already attached to a mention', function () {
    expect(markup()->decode('<@U01|franco> mandó esto'))->toBe('@franco mandó esto');
});

it('falls back to the raw id for an unknown user', function () {
    expect(markup()->decode('<@U77>'))->toBe('@U77');
});

it('renders channel links with and without a label', function () {
    expect(markup()->decode('mirá <#C99|eng-leadership> y <#C99>'))
        ->toBe('mirá #eng-leadership y #eng-leadership');
});

it('renders a labelled link as label then url', function () {
    expect(markup()->decode('<https://linear.app/x|el ticket>'))
        ->toBe('el ticket (https://linear.app/x)');
});

it('renders a bare link as the url', function () {
    expect(markup()->decode('<https://publica.la>'))->toBe('https://publica.la');
});

it('decodes entities before reading markup so escaped urls survive', function () {
    expect(markup()->decode('<https://x.test/a?b=1&amp;c=2|reporte>'))
        ->toBe('reporte (https://x.test/a?b=1&c=2)');
});

it('decodes bare entities', function () {
    expect(markup()->decode('5 &lt; 6 &amp;&amp; 7 &gt; 6'))->toBe('5 < 6 && 7 > 6');
});

it('renders broadcast and group mentions', function () {
    expect(markup()->decode('<!here> <!channel> <!subteam^S01|@eng>'))
        ->toBe('@here @channel @eng');
});

it('renders mailto links', function () {
    expect(markup()->decode('<mailto:fgilio@publica.la|Franco>'))->toBe('Franco');
});

it('leaves plain text untouched', function () {
    expect(markup()->decode('deploy listo, revisamos mañana'))
        ->toBe('deploy listo, revisamos mañana');
});
