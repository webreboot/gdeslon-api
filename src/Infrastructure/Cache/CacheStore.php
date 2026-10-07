<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Cache;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

interface CacheStore
{
    /**
     * @throws InvalidArgumentException
     */
    public function get(string $key): ?string;

    /**
     * @throws InvalidArgumentException
     */
    public function set(string $key, string $value): bool;
}
