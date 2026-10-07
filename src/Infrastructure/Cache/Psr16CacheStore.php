<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Cache;

use Psr\SimpleCache\CacheInterface;

final class Psr16CacheStore implements CacheStore
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function get(string $key): ?string
    {
        try {
            $value = $this->cache->get($key);
        } catch (\Exception) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): bool
    {
        try {
            return $this->cache->set($key, $value);
        } catch (\Exception) {
            return false;
        }
    }
}
