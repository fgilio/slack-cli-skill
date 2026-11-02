<?php

namespace App\Archive;

use RuntimeException;

/**
 * Where an archive's reading copy first parts from a fresh render.
 *
 * The file is named the way it sits in the archive directory
 * (raw.md, raw/2026-10.md). A whole month file can also be
 * missing from the archive, or there without a render.
 */
final class RenderDifference
{
    public const LINE = 'line';

    public const MISSING = 'missing';

    public const UNEXPECTED = 'unexpected';

    private function __construct(
        public readonly string $kind,
        public readonly string $file,
        public readonly ?int $line = null,
    ) {}

    /**
     * Compare a file with its render, line by line.
     */
    public static function between(string $file, string $current, string $rendered): ?self
    {
        $line = self::firstDifferentLine($current, $rendered);

        return $line === null ? null : new self(self::LINE, $file, $line);
    }

    public static function missing(string $file): self
    {
        return new self(self::MISSING, $file);
    }

    public static function unexpected(string $file): self
    {
        return new self(self::UNEXPECTED, $file);
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::LINE => "{$this->file}:{$this->line}",
            default => "{$this->kind} {$this->file}",
        };
    }

    /**
     * @return array{difference: string, file: string, line: ?int}
     */
    public function toArray(): array
    {
        return ['difference' => $this->describe(), 'file' => $this->file, 'line' => $this->line];
    }

    private static function firstDifferentLine(string $current, string $rendered): ?int
    {
        $left = fopen($current, 'rb');
        $right = fopen($rendered, 'rb');

        throw_if($left === false || $right === false, RuntimeException::class, "Unable to read {$current} to compare it with the render");

        try {
            for ($line = 1; ; $line++) {
                $currentLine = fgets($left);
                $renderedLine = fgets($right);

                if ($currentLine === false && $renderedLine === false) {
                    return null;
                }

                if ($currentLine !== $renderedLine) {
                    return $line;
                }
            }
        } finally {
            fclose($left);
            fclose($right);
        }
    }
}
