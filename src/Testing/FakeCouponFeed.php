<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Testing;

use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponFeed;
use Webreboot\GdeSlon\Domain\Promo\CouponKind;
use Webreboot\GdeSlon\Domain\Promo\CouponList;

final class FakeCouponFeed implements CouponFeed
{
    /** @var list<CouponCriteria> */
    private array $criteria = [];

    /**
     * @param list<Coupon>     $coupons
     * @param list<CouponKind> $kinds
     * @param list<string>     $skipped
     */
    public function __construct(private readonly array $coupons = [], private readonly array $kinds = [], private readonly array $skipped = [])
    {
    }

    public function find(CouponCriteria $criteria): CouponList
    {
        $this->criteria[] = $criteria;
        $merchants = array_map(static fn ($merchant): int => $merchant->value(), $criteria->merchants());

        return new CouponList($criteria, array_values(array_filter(
            $this->coupons,
            static fn (Coupon $coupon): bool => $merchants === [] || in_array($coupon->merchantId()->value(), $merchants, true),
        )), $this->kinds, $this->skipped);
    }

    /**
     * @return list<CouponCriteria>
     */
    public function criteria(): array
    {
        return $this->criteria;
    }
}
