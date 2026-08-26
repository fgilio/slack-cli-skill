<?php

namespace App\Archive;

/**
 * Turns Slack's wire markup into the plain prose an archive reads best in.
 *
 * Entities are decoded before the angle-bracket constructs so that a URL
 * carrying an escaped ampersand survives into its rendered link.
 */
final class SlackMarkup
{
    public function __construct(private readonly NameResolver $names) {}

    public function decode(string $text): string
    {
        $text = strtr($text, [
            '&amp;' => '&',
            '&lt;' => '<',
            '&gt;' => '>',
        ]);

        $text = preg_replace_callback(
            '/<@([UWB][A-Z0-9]+)(?:\|([^>]*))?>/',
            fn (array $m): string => '@'.(($m[2] ?? '') !== '' ? $m[2] : $this->names->userName($m[1])),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/<#([CDG][A-Z0-9]+)(?:\|([^>]*))?>/',
            fn (array $m): string => '#'.(($m[2] ?? '') !== '' ? $m[2] : $this->names->channelName($m[1])),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/<!subteam\^[A-Z0-9]+(?:\|([^>]*))?>/',
            fn (array $m): string => '@'.ltrim($m[1] ?? 'grupo', '@'),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/<!(here|channel|everyone)(?:\|[^>]*)?>/',
            fn (array $m): string => '@'.$m[1],
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/<!date\^\d+\^([^^>|]*)(?:\^[^>|]*)?(?:\|([^>]*))?>/',
            fn (array $m): string => ($m[2] ?? '') !== '' ? $m[2] : $m[1],
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/<mailto:([^|>]+)(?:\|([^>]*))?>/',
            fn (array $m): string => ($m[2] ?? '') !== '' ? $m[2] : $m[1],
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/<((?:https?|slack):[^|>]+)(?:\|([^>]*))?>/',
            fn (array $m): string => ($m[2] ?? '') !== '' ? $m[2].' ('.$m[1].')' : $m[1],
            $text
        ) ?? $text;

        return $text;
    }
}
