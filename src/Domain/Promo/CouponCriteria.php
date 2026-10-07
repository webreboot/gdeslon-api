<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Фильтр купонов: магазины и виды (любой из). Пусто — все купоны магазинов вебмастера. Магазин, не подключённый
 * вебмастеру, API отвергает (CouponCriteriaRejectedException). Неизменяемый; новые параметры — только в конец.
 */
final class CouponCriteria
{
    /** @var list<MerchantId> */
    private readonly array $merchants;

    /** @var list<int> */
    private readonly array $kinds;

    /**
     * @param list<MerchantId|int> $merchants
     * @param list<int>            $kinds     ID видов (CouponList::kinds())
     */
    public function __construct(array $merchants = [], array $kinds = [])
    {
        $unique = [];
        foreach ($merchants as $merchant) {
            $merchant = is_int($merchant) ? new MerchantId($merchant) : $merchant;
            $unique[$merchant->value()] ??= $merchant;
        }
        $this->merchants = array_values($unique);

        $kindIds = [];
        foreach ($kinds as $kind) {
            if ($kind <= 0) {
                throw new InvalidArgumentException(sprintf('ID вида купона должен быть положительным, получено %d', $kind));
            }
            $kindIds[$kind] = $kind;
        }
        $this->kinds = array_values($kindIds);
    }

    public static function forMerchant(int|MerchantId $merchant): self
    {
        return new self([$merchant]);
    }

    /**
     * @return list<MerchantId>
     */
    public function merchants(): array
    {
        return $this->merchants;
    }

    /**
     * @return list<int>
     */
    public function kinds(): array
    {
        return $this->kinds;
    }
}
