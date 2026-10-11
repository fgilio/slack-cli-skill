<?php

namespace Tests\Support;

/**
 * A `shortwrite://` stream over the real filesystem that writes nothing to
 * a `.part` file, the way fwrite() behaves on a full disk.
 */
final class ShortWriteStream
{
    public const SCHEME = 'shortwrite';

    /** @var resource|null */
    public $context;

    /** @var resource */
    private $handle;

    private bool $full = false;

    public function stream_open(string $path, string $mode): bool
    {
        $handle = fopen(self::real($path), $mode);

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;
        $this->full = str_ends_with($path, '.part');

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, max(1, $count));
    }

    public function stream_write(string $data): int
    {
        return $this->full ? 0 : (int) fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return @stat(self::real($path));
    }

    public function unlink(string $path): bool
    {
        return unlink(self::real($path));
    }

    public function rename(string $from, string $to): bool
    {
        return rename(self::real($from), self::real($to));
    }

    private static function real(string $path): string
    {
        return substr($path, strlen(self::SCHEME.'://'));
    }
}
