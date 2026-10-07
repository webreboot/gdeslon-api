<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Вид купона из справочника API: «скидка на заказ», «SALE», «подарок к заказу»… ID — для фильтра CouponCriteria::kinds.
 */
final class CouponKind
{
    private readonly string $name;

    /**
     * @param int|null $id ID вида; null — вид без ID в справочнике
     */
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

    /**
     * По ID, а у видов без ID — по названию.
     */
    public function equals(self $other): bool
    {
        return $this->id !== null || $other->id !== null ? $this->id === $other->id : $this->name === $other->name;
    }
}
