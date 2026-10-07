<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Normalizer;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
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
use Webreboot\GdeSlon\Tests\Support\Fixtures;

/**
 * Схема JSON CLI (и будущего MCP) — публичный контракт (docs/cli.md, раздел «Поля»): ключи, их порядок и типы, в том
 * числе вложенные. Проверяется каждый объект из фикстур. Переименование ключа или смена типа ломает этот тест.
 *
 * Нотация: 'int|null' — допустимые типы; ['[]' => X] — список из X; ['?' => X] — X или null; массив с ключами —
 * объект ровно с этими ключами в этом порядке.
 */
final class JsonSchemaTest extends TestCase
{
    private const MONEY = ['amount' => 'string', 'currency' => 'string'];

    private const CATEGORY = [
        'id' => 'int',
        'parent_id' => 'int|null',
        'name' => 'string',
        'archived' => 'bool',
        'path' => ['[]' => 'int'],
        'depth' => 'int',
        'offer_count' => 'int|null',
    ];

    private const MERCHANT = [
        'id' => 'int',
        'name' => 'string',
        'url' => 'string',
        'domain' => 'string',
        'short_description' => 'string',
        'description' => 'string',
        'conditions' => 'string',
        'logo_url' => 'string|null',
        'country' => 'string|null',
        'kind' => 'string|null',
        'green' => 'bool',
        'commission_summary' => 'string|null',
        'categories' => ['[]' => ['id' => 'int', 'name' => 'string|null']],
        'affiliate_link' => 'string|null',
        'traffic_types' => ['[]' => ['name' => 'string', 'allowed' => 'bool']],
        'tariffs' => ['[]' => [
            'id' => 'string',
            'title' => 'string|null',
            'rate_type' => 'string',
            'rate' => 'string',
            'traffic_categories' => ['[]' => 'string'],
            'product_categories' => ['[]' => 'string'],
        ]],
        'category_tariffs' => ['[]' => ['merchant_category_id' => 'int', 'name' => 'string|null', 'rate_type' => 'string', 'rate' => 'string']],
        'ad_marking' => 'string|null',
    ];

    private const OFFER = [
        'id' => 'string',
        'merchant_id' => 'int',
        'name' => 'string',
        'price' => self::MONEY,
        'old_price' => ['?' => self::MONEY],
        'charge' => ['?' => self::MONEY],
        'affiliate_link' => 'string',
        'article' => 'string|null',
        'category_id' => 'int|null',
        'available' => 'bool',
        'picture' => 'string|null',
        'thumbnail' => 'string|null',
        'original_picture' => 'string|null',
        'description' => 'string|null',
        'vendor' => 'string|null',
        'model' => 'string|null',
        'product_url' => 'string|null',
        'ad_marking' => 'string|null',
    ];

    private const ORDER = [
        'id' => 'string',
        'merchant_id' => 'int',
        'merchant_name' => 'string|null',
        'state' => 'string',
        'type' => 'string',
        'reward' => self::MONEY,
        'amount' => ['?' => self::MONEY],
        'merchant_order_number' => 'string|null',
        'sub_id' => 'string|null',
        'affiliate_id' => 'int|null',
        'item_count' => 'int|null',
        'transition_at' => 'string|null',
        'created_at' => 'string|null',
        'last_updated_at' => 'string|null',
        'confirmed_at' => 'string|null',
        'accrued_at' => 'string|null',
        'keywords' => 'string|null',
    ];

    private const CLAIM = [
        'id' => 'int',
        'order_number' => 'string',
        'order_date' => 'string',
        'order_total' => 'string',
        'merchant_id' => 'int',
        'merchant_name' => 'string|null',
        'order_status' => 'string',
        'claim_state' => 'string',
        'description' => 'string|null',
        'attachment_url' => 'string|null',
        'order_updated_at' => 'string|null',
    ];

    private const NEW_CLAIM = [
        'order_number' => 'string',
        'order_date' => 'string',
        'order_total' => 'string',
        'merchant_id' => 'int',
        'description' => 'string|null',
        'attachment' => ['file_name' => 'string', 'type' => 'string', 'size' => 'int'],
    ];

    private const COUPON_KIND = ['id' => 'int|null', 'name' => 'string'];

    private const COUPON = [
        'id' => 'int',
        'merchant_id' => 'int',
        'merchant_name' => 'string|null',
        'name' => 'string',
        'description' => 'string',
        'instruction' => 'string|null',
        'code' => 'string|null',
        'kind' => self::COUPON_KIND,
        'categories' => ['[]' => ['id' => 'int', 'name' => 'string|null']],
        'starts_at' => 'string',
        'ends_at' => 'string',
        'affiliate_link' => 'string',
        'affiliate_link_with_code' => 'string|null',
        'ad_marking' => 'string|null',
    ];

    public function testCategories(): void
    {
        $tree = (new CategoryMapper())->toTree(Fixtures::json('categories/categories.json'));

        self::assertShape(['categories' => ['[]' => self::CATEGORY]], ['categories' => array_map(CatalogNormalizer::category(...), $tree->all())]);
    }

    public function testMerchants(): void
    {
        foreach (['merchants/shops.xml', 'merchants/shops-public.xml'] as $fixture) {
            $list = (new MerchantXmlParser())->parse(Fixtures::read($fixture));
            self::assertShape(['merchants' => ['[]' => self::MERCHANT], 'skipped' => ['[]' => 'string']], CatalogNormalizer::merchantList($list), $fixture);
        }
    }

    public function testSearch(): void
    {
        $result = (new OfferXmlParser())->parse(Fixtures::read('search/search.xml'), new SearchCriteria(query: 'платье', limit: 8));

        self::assertShape([
            'query' => 'string|null',
            'page' => 'int',
            'limit' => 'int',
            'total' => 'int|null',
            'next_page' => 'int|null',
            'offers' => ['[]' => self::OFFER],
            'skipped' => ['[]' => 'string'],
        ], CatalogNormalizer::searchResult($result));
    }

    public function testOrders(): void
    {
        $list = (new OrderMapper())->toList(Fixtures::json('orders/orders-synthetic.json'), new OrderCriteria());

        self::assertShape(['orders' => ['[]' => self::ORDER], 'skipped' => ['[]' => 'string']], SalesNormalizer::orderList($list));
    }

    public function testClaims(): void
    {
        $list = (new LostOrderClaimMapper())->toList(Fixtures::json('lost-orders/claims-synthetic.json'), new LostOrderCriteria());
        self::assertShape(['claims' => ['[]' => self::CLAIM], 'skipped' => ['[]' => 'string']], ClaimsNormalizer::claimList($list));

        $new = new NewLostOrderClaim('GS123L', '2026-09-24', '554.34', 2573, ClaimAttachment::fromContents('receipt.pdf', "%PDF-1.4\n"));
        self::assertShape(['dry_run' => 'bool', 'claim' => self::NEW_CLAIM], ['dry_run' => true, 'claim' => ClaimsNormalizer::newClaim($new)]);
    }

    public function testCoupons(): void
    {
        $list = (new CouponXmlParser())->parse(Fixtures::read('coupons/coupons.xml'), new CouponCriteria());

        self::assertShape(
            ['coupons' => ['[]' => self::COUPON], 'kinds' => ['[]' => self::COUPON_KIND], 'skipped' => ['[]' => 'string']],
            (new PromoNormalizer())->couponList($list),
        );
    }

    public function testShapeCheckerRejectsDrift(): void
    {
        $failures = 0;
        foreach ([
            [['a' => 'int'], ['a' => '1']],
            [['a' => 'int'], ['a' => 1, 'b' => 2]],
            [['a' => 'int', 'b' => 'int'], ['b' => 1, 'a' => 2]],
            [['a' => ['[]' => 'int']], ['a' => ['x' => 1]]],
            [['a' => self::MONEY], ['a' => null]],
        ] as [$expected, $actual]) {
            try {
                self::assertShape($expected, $actual);
            } catch (\PHPUnit\Framework\AssertionFailedError) {
                $failures++;
            }
        }

        self::assertSame(5, $failures);
    }

    /**
     * @param array<array-key, mixed>|string $expected
     */
    private static function assertShape(array|string $expected, mixed $actual, string $path = '$'): void
    {
        if (is_string($expected)) {
            self::assertContains(get_debug_type($actual), explode('|', $expected), $path);

            return;
        }
        if (array_key_exists('?', $expected)) {
            if ($actual !== null) {
                self::assertShapeOf($expected['?'], $actual, $path);
            }

            return;
        }
        if (array_key_exists('[]', $expected)) {
            self::assertIsArray($actual, $path);
            self::assertTrue(array_is_list($actual), $path . ' — список');
            foreach ($actual as $i => $item) {
                self::assertShapeOf($expected['[]'], $item, $path . '[' . $i . ']');
            }

            return;
        }
        self::assertIsArray($actual, $path);
        self::assertSame(array_keys($expected), array_keys($actual), $path . ' — ключи и порядок');
        foreach ($expected as $key => $shape) {
            self::assertShapeOf($shape, $actual[$key], $path . '.' . $key);
        }
    }

    private static function assertShapeOf(mixed $expected, mixed $actual, string $path): void
    {
        self::assertTrue(is_string($expected) || is_array($expected), $path);
        self::assertShape($expected, $actual, $path);
    }
}
