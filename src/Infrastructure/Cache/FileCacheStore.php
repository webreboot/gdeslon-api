<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Cache;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class FileCacheStore implements CacheStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $value = @file_get_contents($path);

        return $value === false ? null : $value;
    }

    public function set(string $key, string $value): bool
    {
        $path = $this->path($key);
        if (!$this->ensureDirectory()) {
            return false;
        }

        $temporary = sprintf('%s/.%s.%s.tmp', $this->directory, $key, bin2hex(random_bytes(6)));
        if (@file_put_contents($temporary, $value, LOCK_EX) !== strlen($value)) {
            @unlink($temporary);

            return false;
        }
        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    private function path(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.]{0,63}\z/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Недопустимый ключ кэша «%s»', $key));
        }

        return $this->directory . '/' . $key;
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }
        if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return false;
        }

        return @chmod($this->directory, 0700);
    }
}
