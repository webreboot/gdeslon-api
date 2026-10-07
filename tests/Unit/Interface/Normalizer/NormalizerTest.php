<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Normalizer;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\RateType;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\CategoryMapper;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\MerchantXmlParser;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\OfferXmlParser;
use Webreboot\GdeSlon\Infrastructure\Api\Claims\LostOrderClaimMapper;
use Webreboot\GdeSlon\Infrastructure\Api\Promo\CouponXmlParser;
use Webreboot\GdeSlon\Infrastructure\Api\Sales\OrderMapper;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\ClaimsNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\PromoNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\SalesNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\ValueNormalizer;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

/**
 * Схема JSON — общий публичный контракт CLI и MCP (docs/cli.md): ключи snake_case присутствуют всегда, деньги строкой.
 */
final class NormalizerTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame(['amount' => '1999.99', 'currency' => 'RUR'], ValueNormalizer::money(new Money('1999.99', 'RUR')));
        self::assertNull(ValueNormalizer::money(null));
        self::assertSame('2026-09-01T10:00:00+03:00', ValueNormalizer::moment(new \DateTimeImmutable('2026-09-01T10:00:00.123456+03:00')));
        self::assertSame('2026-09-24', ValueNormalizer::date(new \DateTimeImmutable('2026-09-24T00:00:00+03:00')));
        self::assertNull(ValueNormalizer::moment(null));
    }

    public function testCategory(): void
    {
        $tree = (new CategoryMapper())->toTree(Fixtures::json('categories/categories.json'));
        $category = $tree->all()[0];

        $data = CatalogNormalizer::category($category);

        self::assertSame(['id', 'parent_id', 'name', 'archived', 'path', 'depth', 'offer_count'], array_keys($data));
        self::assertSame($category->id()->value(), $data['id']);
        self::assertIsArray($data['path']);
        self::assertContainsOnly('int', $data['path']);
    }

    public function testMerchant(): void
    {
        $list = (new MerchantXmlParser())->parse(Fixtures::read('merchants/shops.xml'));
        $data = CatalogNormalizer::merchantList($list);

        self::assertSame(['merchants', 'skipped'], array_keys($data));
        $merchant = $data['merchants'][0];
        self::assertSame(['id', 'name', 'url', 'domain', 'short_description', 'description', 'conditions', 'logo_url', 'country', 'kind', 'green',
            'commission_summary', 'categories', 'affiliate_link', 'traffic_types', 'tariffs', 'category_tariffs', 'ad_marking'], array_keys($merchant));
        self::assertIsString($merchant['affiliate_link']);

        $public = CatalogNormalizer::merchantList((new MerchantXmlParser())->parse(Fixtures::read('merchants/shops-public.xml')));
        self::assertNull($public['merchants'][0]['affiliate_link']);
    }

    public function testSearchResult(): void
    {
        $result = (new OfferXmlParser())->parse(Fixtures::read('search/search.xml'), new SearchCriteria(query: 'платье', limit: 8));

        $data = CatalogNormalizer::searchResult($result);

        self::assertSame(['query', 'page', 'limit', 'total', 'next_page', 'offers', 'skipped'], array_keys($data));
        self::assertSame('платье', $data['query']);
        self::assertSame(2, $data['next_page']);
        $offers = $data['offers'];
        self::assertIsArray($offers);
        self::assertCount(8, $offers);
        self::assertIsArray($offers[0]);
        self::assertSame(['id', 'merchant_id', 'name', 'price', 'old_price', 'charge', 'affiliate_link', 'article', 'category_id', 'available', 'picture',
            'thumbnail', 'original_picture', 'description', 'vendor', 'model', 'product_url', 'ad_marking'], array_keys($offers[0]));
        self::assertIsArray($offers[0]['price']);
        self::assertArrayHasKey('amount', $offers[0]['price']);

        $empty = CatalogNormalizer::searchResult((new OfferXmlParser())->parse(Fixtures::read('search/search-empty.xml'), new SearchCriteria()));
        self::assertSame([], $empty['offers']);
        self::assertNull($empty['next_page']);
    }

    public function testOrders(): void
    {
        $list = (new OrderMapper())->toList(Fixtures::json('orders/orders-synthetic.json'), new OrderCriteria());

        $data = SalesNormalizer::orderList($list);

        self::assertSame(['orders', 'skipped'], array_keys($data));
        $order = $data['orders'][0];
        self::assertSame('81234567', $order['id']);
        self::assertSame('confirmed', $order['state']);
        self::assertSame('product', $order['type']);
        self::assertSame(['amount' => '150.00', 'currency' => 'RUB'], $order['reward']);
        self::assertSame('2026-09-01T10:15:00+03:00', $order['created_at']);
        self::assertNull($order['accrued_at']);
        self::assertSame('lead', $data['orders'][1]['type']);
        self::assertNull($data['orders'][1]['amount']);
    }

    public function testClaims(): void
    {
        $list = (new LostOrderClaimMapper())->toList(Fixtures::json('lost-orders/claims-synthetic.json'), new LostOrderCriteria());

        $data = ClaimsNormalizer::claimList($list);

        self::assertSame(['claims', 'skipped'], array_keys($data));
        self::assertSame(['id', 'order_number', 'order_date', 'order_total', 'merchant_id', 'merchant_name', 'order_status', 'claim_state', 'description',
            'attachment_url', 'order_updated_at'], array_keys($data['claims'][0]));
        self::assertSame('12020.22', $data['claims'][0]['order_total']);
        self::assertSame('2026-09-24', $data['claims'][0]['order_date']);
        self::assertSame('waiting', $data['claims'][0]['order_status']);
        self::assertSame('in_work', $data['claims'][0]['claim_state']);

        $new = ClaimsNormalizer::newClaim(new NewLostOrderClaim('GS123L', '2026-09-24', '554.34', 2573, ClaimAttachment::fromContents('receipt.pdf', "%PDF-1.4\nSECRET-RECEIPT")));
        self::assertSame(['file_name' => 'receipt.pdf', 'type' => 'pdf', 'size' => strlen("%PDF-1.4\nSECRET-RECEIPT")], $new['attachment']);
        self::assertStringNotContainsString('SECRET-RECEIPT', (string) json_encode($new));
    }

    public function testCouponsMaskLinksByDefault(): void
    {
        $list = (new CouponXmlParser())->parse(Fixtures::read('coupons/coupons.xml'), new CouponCriteria());

        $masked = (new PromoNormalizer())->couponList($list);
        $json = (string) json_encode($masked);

        self::assertSame(['coupons', 'kinds', 'skipped'], array_keys($masked));
        self::assertStringNotContainsString('0a1b2c3d4e', $json);
        $coupon = $masked['coupons'][0];
        self::assertSame('http://xf.gdeslon.ru/ck/***/336004?erid=2SDnjTEST001', $coupon['affiliate_link']);
        self::assertSame(['id' => 1, 'name' => 'скидка на заказ'], $coupon['kind']);
        self::assertSame('2026-12-31T23:59:59+03:00', $coupon['ends_at']);
        self::assertSame('PROMO10', $coupon['code']);

        $withoutCode = (new PromoNormalizer())->coupon($list->find(400636) ?? throw new \LogicException());
        self::assertNull($withoutCode['code']);
        self::assertNull($withoutCode['affiliate_link_with_code']);

        $revealed = (new PromoNormalizer(revealLinks: true))->coupon($list->find(336004) ?? throw new \LogicException());
        self::assertIsString($revealed['affiliate_link']);
        self::assertStringContainsString('/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/', $revealed['affiliate_link']);
        self::assertSame('http://xf.gdeslon.ru/ck/***/1?kc=X', Coupon::maskLink('http://xf.gdeslon.ru/ck/abc/1?kc=X'));
    }

    public function testEveryEnumCaseHasJsonValue(): void
    {
        foreach (OrderState::cases() as $state) {
            self::assertNotSame('', SalesNormalizer::state($state));
        }
        foreach (OrderType::cases() as $type) {
            self::assertNotSame('', SalesNormalizer::type($type));
        }
        foreach (LostOrderStatus::cases() as $status) {
            self::assertNotSame('', ClaimsNormalizer::orderStatus($status));
        }
        foreach (LostOrderClaimState::cases() as $state) {
            self::assertNotSame('', ClaimsNormalizer::claimState($state));
        }
        foreach (RateType::cases() as $rate) {
            self::assertNotSame('', CatalogNormalizer::rateType($rate));
        }
        self::assertSame(['new', 'cancelled', 'pending', 'confirmed', 'paid'], array_map(SalesNormalizer::state(...), OrderState::cases()));
    }
}
