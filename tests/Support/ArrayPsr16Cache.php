<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Минимальный PSR-16 кэш в памяти; failing() — каждый вызов бросает исключение PSR-16.
 */
final class ArrayPsr16Cache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    private bool $failing = false;

    private ?\Throwable $failure = null;

    public function failing(): self
    {
        $this->failing = true;

        return $this;
    }

    /**
     * Каждый вызов бросает заданное исключение (не из PSR-16 — как драйвер бэкенда).
     */
    public function failingWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->guard();

        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->guard();
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    private function guard(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->failing) {
            throw new class ('кэш недоступен') extends \RuntimeException implements InvalidArgumentException {
            };
        }
    }
}
