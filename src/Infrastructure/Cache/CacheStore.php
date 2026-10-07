<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Cache;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Хранилище кэша «ключ → строка». Реализации: FileCacheStore, Psr16CacheStore или своя.
 *
 * Ключи, которые передаёт библиотека, состоят из [A-Za-z0-9_.], не длиннее 64 символов (совместимо с PSR-16).
 * Ошибки хранилища не должны ломать запросы к API: get() при сбое возвращает null, set() — false.
 */
interface CacheStore
{
    /**
     * @throws InvalidArgumentException недопустимый ключ
     */
    public function get(string $key): ?string;

    /**
     * @return bool записано ли значение
     *
     * @throws InvalidArgumentException недопустимый ключ
     */
    public function set(string $key, string $value): bool;
}
