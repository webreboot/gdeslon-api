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
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;
use Webreboot\GdeSlon\Interface\Normalizer\PromoNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\SalesNormalizer;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

/**
 * Схема JSON CLI и MCP — публичный контракт (docs/cli.md, раздел «Поля»): вывод нормализаторов соответствует формам
 * JsonShapes — ключи, их порядок и типы, в том числе вложенные. Проверяется каждый объект из фикстур.
 */
final class JsonSchemaTest extends TestCase
{
    public function testCategories(): void
    {
        $tree = (new CategoryMapper())->toTree(Fixtures::json('categories/categories.json'));

        self::assertShape(['categories' => ['[]' => JsonShapes::CATEGORY]], ['categories' => array_map(CatalogNormalizer::category(...), $tree->all())]);
    }

    public function testMerchants(): void
    {
        foreach (['merchants/shops.xml', 'merchants/shops-public.xml'] as $fixture) {
            $list = (new MerchantXmlParser())->parse(Fixtures::read($fixture));
            self::assertShape(['merchants' => ['[]' => JsonShapes::MERCHANT], 'skipped' => ['[]' => 'string']], CatalogNormalizer::merchantList($list), $fixture);
        }
    }

    public function testMerchantSummary(): void
    {
        foreach (['merchants/shops.xml', 'merchants/shops-public.xml'] as $fixture) {
            $list = (new MerchantXmlParser())->parse(Fixtures::read($fixture));
            self::assertShape(['[]' => JsonShapes::MERCHANT_SUMMARY], array_map(CatalogNormalizer::merchantSummary(...), $list->all()), $fixture);
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
            'offers' => ['[]' => JsonShapes::OFFER],
            'skipped' => ['[]' => 'string'],
        ], CatalogNormalizer::searchResult($result));
    }

    public function testOrders(): void
    {
        $list = (new OrderMapper())->toList(Fixtures::json('orders/orders-synthetic.json'), new OrderCriteria());

        self::assertShape(['orders' => ['[]' => JsonShapes::ORDER], 'skipped' => ['[]' => 'string']], SalesNormalizer::orderList($list));
    }

    public function testClaims(): void
    {
        $list = (new LostOrderClaimMapper())->toList(Fixtures::json('lost-orders/claims-synthetic.json'), new LostOrderCriteria());
        self::assertShape(['claims' => ['[]' => JsonShapes::CLAIM], 'skipped' => ['[]' => 'string']], ClaimsNormalizer::claimList($list));

        $new = new NewLostOrderClaim('GS123L', '2026-09-24', '554.34', 2573, ClaimAttachment::fromContents('receipt.pdf', "%PDF-1.4\n"));
        self::assertShape(['dry_run' => 'bool', 'claim' => JsonShapes::NEW_CLAIM], ['dry_run' => true, 'claim' => ClaimsNormalizer::newClaim($new)]);
    }

    public function testCoupons(): void
    {
        $list = (new CouponXmlParser())->parse(Fixtures::read('coupons/coupons.xml'), new CouponCriteria());

        self::assertShape(
            ['coupons' => ['[]' => JsonShapes::COUPON], 'kinds' => ['[]' => JsonShapes::COUPON_KIND], 'skipped' => ['[]' => 'string']],
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
            [['a' => JsonShapes::MONEY], ['a' => null]],
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
