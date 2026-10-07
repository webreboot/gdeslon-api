<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * ID заявки на потерянный заказ (`id` в API).
 */
final class LostOrderClaimId
{
    private readonly int $value;

    public function __construct(int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('ID заявки должен быть положительным, получено %d', $value));
        }

        $this->value = $value;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
