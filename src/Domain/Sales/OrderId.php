<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * ID заказа в «Где Слон?» (`gdeslon_order_id`). Строкой: формат API не гарантирован, а большие числа не теряют точность.
 */
final class OrderId
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Пустой ID заказа');
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
