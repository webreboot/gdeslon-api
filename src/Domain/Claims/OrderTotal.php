<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OrderTotal
{
    private function __construct(private readonly string $amount)
    {
    }

    public static function of(string|int|float $value): self
    {
        $decimal = match (true) {
            is_string($value) => trim($value),
            is_int($value) => (string) $value,
            default => self::fromFloat($value),
        };
        if ($decimal === null || preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?\z/', $decimal, $match) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Сумма заказа «%s»: ожидалось неотрицательное число не больше 13 цифр и 2 знаков после точки',
                is_float($value) ? var_export($value, true) : (string) $value,
            ));
        }

        $integer = ltrim($match[1], '0');

        return new self(($integer === '' ? '0' : $integer) . '.' . str_pad($match[2] ?? '', 2, '0'));
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    private static function fromFloat(float $value): ?string
    {
        if (!is_finite($value)) {
            return null;
        }
        $decimal = sprintf('%.2F', $value);

        return (float) $decimal === $value ? $decimal : null;
    }
}
