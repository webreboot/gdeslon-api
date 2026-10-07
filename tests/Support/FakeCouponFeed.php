<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponFeed;
use Webreboot\GdeSlon\Domain\Promo\CouponList;

/**
 * Купоны без сети: заданные купоны, отфильтрованные по магазинам; запоминает последние критерии.
 */
final class FakeCouponFeed implements CouponFeed
{
    public ?CouponCriteria $lastCriteria = null;

    /**
     * @param list<Coupon> $coupons
     */
    public function __construct(private readonly array $coupons = [])
    {
    }

    public function find(CouponCriteria $criteria): CouponList
    {
        $this->lastCriteria = $criteria;
        $merchants = array_map(static fn ($merchant): int => $merchant->value(), $criteria->merchants());

        return new CouponList($criteria, array_values(array_filter(
            $this->coupons,
            static fn (Coupon $coupon): bool => $merchants === [] || in_array($coupon->merchantId()->value(), $merchants, true),
        )));
    }
}
