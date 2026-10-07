<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Infrastructure\Cache\CacheStore;

final class InMemoryCacheStore implements CacheStore
{
    /** @var array<string, string> */
    private array $values = [];

    private bool $failWrites = false;

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): bool
    {
        if ($this->failWrites) {
            return false;
        }
        $this->values[$key] = $value;

        return true;
    }

    public function failWrites(): self
    {
        $this->failWrites = true;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
