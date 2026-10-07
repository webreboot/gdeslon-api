<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\TrafficType;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class MerchantTest extends TestCase
{
    public function testMinimalMerchantDefaults(): void
    {
        $merchant = new Merchant(new MerchantId(105263), 'superstep.ru', 'https://superstep.ru/');

        self::assertSame(105263, $merchant->id()->value());
        self::assertSame('superstep.ru', $merchant->name());
        self::assertSame('https://superstep.ru/', $merchant->url());
        self::assertSame('', $merchant->shortDescription());
        self::assertSame('', $merchant->description());
        self::assertSame('', $merchant->conditions());
        self::assertNull($merchant->logoUrl());
        self::assertNull($merchant->country());
        self::assertNull($merchant->kind());
        self::assertFalse($merchant->isGreen());
        self::assertNull($merchant->commissionSummary());
        self::assertSame([], $merchant->categories());
        self::assertNull($merchant->affiliateLink());
        self::assertSame([], $merchant->trafficTypes());
        self::assertSame([], $merchant->tariffs());
        self::assertSame([], $merchant->categoryTariffs());
        self::assertNull($merchant->adMarking());
    }

    #[DataProvider('invalidMerchants')]
    public function testInvariants(string $name, string $url, ?string $affiliateLink): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Merchant(new MerchantId(1), $name, $url, affiliateLink: $affiliateLink);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function invalidMerchants(): iterable
    {
        yield 'пустое имя' => ['  ', 'https://x.ru/', null];
        yield 'пустой url' => ['x', '', null];
        yield 'url не http' => ['x', 'ftp://x.ru/', null];
        yield 'url без схемы' => ['x', 'komus.ru', null];
        yield 'ссылка не URL' => ['x', 'https://x.ru/', 'sf.gdeslon.ru/cf/x'];
    }

    #[DataProvider('domains')]
    public function testDomain(string $url, string $domain): void
    {
        self::assertSame($domain, (new Merchant(new MerchantId(1), 'x', $url))->domain());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function domains(): iterable
    {
        yield 'www' => ['https://www.komus.ru/', 'komus.ru'];
        yield 'http' => ['http://aliexpress.com/', 'aliexpress.com'];
        yield 'путь' => ['https://skyeng.ru/offers/cpa', 'skyeng.ru'];
        yield 'регистр' => ['https://WWW.Example.RU', 'example.ru'];
    }

    public function testTextsKeepMarkdownAndNormalizeLineEndings(): void
    {
        $merchant = new Merchant(
            new MerchantId(1),
            'x',
            'https://x.ru/',
            description: "**Магазин** «Всё для шитья»\r\n[сайт](https://x.ru)",
            conditions: "Условия:\r\n- пункт",
        );

        self::assertSame("**Магазин** «Всё для шитья»\n[сайт](https://x.ru)", $merchant->description());
        self::assertSame("Условия:\n- пункт", $merchant->conditions());
    }

    public function testTrafficTypes(): void
    {
        $merchant = new Merchant(new MerchantId(1), 'x', 'https://x.ru/', trafficTypes: [
            new TrafficType('Контекстная реклама', true),
            new TrafficType('Дорвеи', false),
            new TrafficType('Cashback', true),
        ]);

        self::assertSame(['Контекстная реклама', 'Cashback'], $merchant->allowedTrafficTypes());
        self::assertSame(['Дорвеи'], $merchant->forbiddenTrafficTypes());
        self::assertTrue($merchant->isTrafficTypeAllowed('Cashback'));
        self::assertFalse($merchant->isTrafficTypeAllowed('Дорвеи'));
        self::assertNull($merchant->isTrafficTypeAllowed('Телепатия'));
    }

    public function testDuplicateTrafficTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Merchant(new MerchantId(1), 'x', 'https://x.ru/', trafficTypes: [new TrafficType('Cashback', true), new TrafficType('Cashback', false)]);
    }

    public function testEmptyOptionalTextsBecomeNull(): void
    {
        $merchant = new Merchant(new MerchantId(1), 'x', 'https://x.ru/', commissionSummary: ' ', adMarking: '');

        self::assertNull($merchant->commissionSummary());
        self::assertNull($merchant->adMarking());
    }
}
