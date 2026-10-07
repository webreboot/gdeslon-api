<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Тип трафика (источник: контекстная реклама, кэшбэк, дорвеи…) и разрешён ли он магазином.
 */
final class TrafficType
{
    private readonly string $name;

    public function __construct(string $name, private readonly bool $allowed)
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Пустое название типа трафика');
        }

        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }
}
