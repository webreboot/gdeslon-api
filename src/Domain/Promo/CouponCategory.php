<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Domain\Catalog\CategoryId;

final class CouponCategory
{
    private readonly CategoryId $id;

    private readonly ?string $name;

    public function __construct(int|CategoryId $id, ?string $name)
    {
        $this->id = is_int($id) ? new CategoryId($id) : $id;
        $name = $name === null ? null : trim($name);
        $this->name = $name === '' ? null : $name;
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}
