<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class CategoryTariff
{
    private readonly ?string $name;

    public function __construct(
        private readonly int $merchantCategoryId,
        ?string $name,
        private readonly bool $percent,
        private readonly string $rate,
    ) {
        if ($merchantCategoryId <= 0) {
            throw new InvalidArgumentException(sprintf('Тариф категории: ID должен быть положительным, получено %d', $merchantCategoryId));
        }
        DecimalRate::assert($rate, 'Тариф категории ' . $merchantCategoryId);

        $name = $name === null ? '' : trim($name);
        $this->name = $name === '' ? null : $name;
    }

    public function merchantCategoryId(): int
    {
        return $this->merchantCategoryId;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function isPercent(): bool
    {
        return $this->percent;
    }

    public function rate(): string
    {
        return $this->rate;
    }
}
