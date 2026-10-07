<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class MerchantCategory
{
    private readonly ?string $name;

    public function __construct(private readonly int $id, ?string $name)
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(sprintf('Категория магазина: ID должен быть положительным, получено %d', $id));
        }

        $name = $name === null ? '' : trim($name);
        $this->name = $name === '' ? null : $name;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}
