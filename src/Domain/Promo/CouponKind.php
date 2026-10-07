<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class CouponKind
{
    private readonly string $name;

    public function __construct(private readonly ?int $id, string $name)
    {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException(sprintf('ID вида купона должен быть положительным, получено %d', $id));
        }
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Пустое название вида купона');
        }

        $this->name = $name;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->id !== null || $other->id !== null ? $this->id === $other->id : $this->name === $other->name;
    }
}
