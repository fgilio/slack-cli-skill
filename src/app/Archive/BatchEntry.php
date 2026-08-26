<?php

namespace App\Archive;

use Illuminate\Support\Str;

/**
 * One conversation a batch run archives.
 */
final class BatchEntry
{
    public function __construct(
        public readonly string $target,
        public readonly string $outDir,
        public readonly ?string $after = null,
        public readonly ?string $before = null,
        public readonly bool $noThreads = false,
    ) {}

    /**
     * The short name the progress line and the summary table show.
     */
    public function label(): string
    {
        return basename($this->outDir);
    }

    /**
     * Whether a --only glob selects this entry. Matching either the target
     * or the directory name means both `--only='@gparra'` and
     * `--only='Slack-DM'` find the same entry.
     */
    public function matches(string $glob): bool
    {
        $glob = Str::lower($glob);

        return fnmatch($glob, Str::lower($this->target))
            || fnmatch($glob, Str::lower($this->label()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'target' => $this->target,
            'out' => $this->outDir,
            'after' => $this->after,
            'before' => $this->before,
            'no_threads' => $this->noThreads ?: null,
        ], fn (mixed $value) => $value !== null);
    }
}
