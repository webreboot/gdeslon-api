<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OfferParam
{
    private readonly string $name;

    private readonly string $value;

    private readonly ?string $unit;

    public function __construct(string $name, string $value, ?string $unit = null)
    {
        $name = self::trimmed($name);
        if ($name === '') {
            throw new InvalidArgumentException('Пустое имя параметра оффера');
        }
        $value = self::trimmed($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Пустое значение параметра оффера «%s»', $name));
        }
        $unit = self::trimmed($unit ?? '');

        $this->name = $name;
        $this->value = $value;
        $this->unit = $unit === '' ? null : $unit;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function unit(): ?string
    {
        return $this->unit;
    }

    private static function trimmed(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? trim($value);
    }
}
