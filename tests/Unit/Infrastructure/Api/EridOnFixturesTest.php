<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\AffiliateLink;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Shared\AdMarking;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\MerchantXmlParser;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\OfferXmlParser;
use Webreboot\GdeSlon\Infrastructure\Api\Promo\CouponXmlParser;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class EridOnFixturesTest extends TestCase
{
    public function testOfferMarkingEridMatchesLink(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__, 3) . '/Fixtures/search/search*.xml') ?: [] as $path) {
            foreach ((new OfferXmlParser())->parse(Fixtures::read('search/' . basename($path)), new SearchCriteria())->offers() as $offer) {
                $checked += self::assertMarkingMatchesLink($offer->adMarking(), $offer->link(), $offer->id());
            }
        }

        self::assertGreaterThanOrEqual(26, $checked);
    }

    public function testMerchantMarkingEridMatchesLink(): void
    {
        $checked = 0;
        foreach (['shops.xml', 'shops-public.xml'] as $fixture) {
            foreach ((new MerchantXmlParser())->parse(Fixtures::read('merchants/' . $fixture))->all() as $merchant) {
                $checked += self::assertMarkingMatchesLink($merchant->adMarking(), $merchant->link(), (string) $merchant->id()->value());
            }
        }

        self::assertGreaterThanOrEqual(9, $checked);
    }

    public function testCouponMarkingEridMatchesLink(): void
    {
        $checked = 0;
        foreach (['coupons.xml', 'coupons-broken.xml'] as $fixture) {
            foreach ((new CouponXmlParser())->parse(Fixtures::read('coupons/' . $fixture), new CouponCriteria())->all() as $coupon) {
                $checked += self::assertMarkingMatchesLink($coupon->adMarking(), $coupon->link(), (string) $coupon->id());
            }
        }

        self::assertGreaterThanOrEqual(9, $checked);
    }

    public function testEveryNonEmptyLinkEridIsAccepted(): void
    {
        $links = [];
        foreach (glob(dirname(__DIR__, 3) . '/Fixtures/search/search*.xml') ?: [] as $path) {
            foreach ((new OfferXmlParser())->parse(Fixtures::read('search/' . basename($path)), new SearchCriteria())->offers() as $offer) {
                $links[] = $offer->link();
            }
        }
        foreach (['shops.xml', 'shops-public.xml'] as $fixture) {
            foreach ((new MerchantXmlParser())->parse(Fixtures::read('merchants/' . $fixture))->all() as $merchant) {
                $links[] = $merchant->link();
            }
        }
        foreach (['coupons.xml', 'coupons-broken.xml'] as $fixture) {
            foreach ((new CouponXmlParser())->parse(Fixtures::read('coupons/' . $fixture), new CouponCriteria())->all() as $coupon) {
                $links[] = $coupon->link();
                $links[] = $coupon->linkWithCode();
            }
        }

        $checked = 0;
        foreach (array_filter($links) as $link) {
            parse_str((string) parse_url($link->url(), PHP_URL_QUERY), $query);
            $erid = $query['erid'] ?? '';
            if (is_string($erid) && $erid !== '') {
                self::assertSame($erid, $link->erid(), $link->url());
                $checked++;
            }
        }

        self::assertGreaterThanOrEqual(44, $checked);
    }

    private static function assertMarkingMatchesLink(?string $marking, ?AffiliateLink $link, string $id): int
    {
        $erid = AdMarking::erid($marking);
        if ($marking === null || stripos($marking, 'erid') === false) {
            self::assertNull($erid, $id);

            return 0;
        }

        self::assertNotNull($erid, $id);
        if ($link !== null) {
            self::assertSame($link->erid(), $erid, $id);
        }

        return 1;
    }
}
