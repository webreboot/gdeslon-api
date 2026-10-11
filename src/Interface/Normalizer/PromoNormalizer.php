<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Normalizer;

use Webreboot\GdeSlon\Domain\Catalog\AffiliateLink;
use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCategory;
use Webreboot\GdeSlon\Domain\Promo\CouponKind;
use Webreboot\GdeSlon\Domain\Promo\CouponList;

/**
 * @internal
 */
final class PromoNormalizer
{
    public function __construct(private readonly bool $revealLinks = false)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function coupon(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id()->value(),
            'merchant_id' => $coupon->merchantId()->value(),
            'merchant_name' => $coupon->merchantName(),
            'name' => $coupon->name(),
            'description' => $coupon->description(),
            'instruction' => $coupon->instruction(),
            'code' => $coupon->code(),
            'kind' => self::kind($coupon->kind()),
            'categories' => array_map(
                static fn (CouponCategory $category): array => ['id' => $category->id()->value(), 'name' => $category->name()],
                $coupon->categories(),
            ),
            'starts_at' => ValueNormalizer::moment($coupon->startsAt()),
            'ends_at' => ValueNormalizer::moment($coupon->endsAt()),
            'affiliate_link' => $this->link($coupon->link()),
            'affiliate_link_with_code' => $coupon->linkWithCode() === null ? null : $this->link($coupon->linkWithCode()),
            'ad_marking' => $coupon->adMarking(),
            'erid' => $coupon->erid(),
        ];
    }

    /**
     * @param list<Coupon>|null $coupons
     *
     * @return array{coupons: list<array<string, mixed>>, kinds: list<array{id: int|null, name: string}>, skipped: list<string>}
     */
    public function couponList(CouponList $list, ?array $coupons = null): array
    {
        return [
            'coupons' => array_map($this->coupon(...), $coupons ?? $list->all()),
            'kinds' => array_map(self::kind(...), $list->kinds()),
            'skipped' => $list->skipped(),
        ];
    }

    /**
     * @return array{id: int|null, name: string}
     */
    public static function kind(CouponKind $kind): array
    {
        return ['id' => $kind->id(), 'name' => $kind->name()];
    }

    private function link(AffiliateLink $link): string
    {
        return $this->revealLinks ? $link->url() : $link->masked();
    }
}
