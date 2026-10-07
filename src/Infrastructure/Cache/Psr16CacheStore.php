<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Кэш поверх PSR-16 (кэш фреймворка: Symfony, Laravel и т.п.). Нужен пакет psr/simple-cache.
 *
 * ```php
 * GdeSlon::create(cache: new Psr16CacheStore($psr16Cache));
 * ```
 *
 * Ошибки хранилища (исключения PSR-16 и драйвера бэкенда) не пробрасываются: при сбое get() возвращает null,
 * set() — false; ошибки PHP (\Error) не перехватываются.
 */
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
            // CacheException PSR-16 и исключения драйвера бэкенда (например RedisException)
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
