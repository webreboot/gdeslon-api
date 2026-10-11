<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\AffiliateLink;
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
    public function testInvariants(string $name, string $url, ?string $affiliateLink, ?string $logoUrl = null): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Merchant(new MerchantId(1), $name, $url, logoUrl: $logoUrl, affiliateLink: $affiliateLink);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?string, 3?: ?string}>
     */
    public static function invalidMerchants(): iterable
    {
        yield 'пустое имя' => ['  ', 'https://x.ru/', null];
        yield 'пустой url' => ['x', '', null];
        yield 'url не http' => ['x', 'ftp://x.ru/', null];
        yield 'url без схемы' => ['x', 'komus.ru', null];
        yield 'ссылка не URL' => ['x', 'https://x.ru/', 'sf.gdeslon.ru/cf/x'];
        yield 'логотип javascript' => ['x', 'https://x.ru/', null, 'javascript:alert(1)'];
        yield 'логотип относительный' => ['x', 'https://x.ru/', null, '/logo.png'];
        yield 'ссылка с пробелом' => ['x', 'https://x.ru/', 'https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=&mid=1 '];
        yield 'ссылка с переводом строки внутри' => ['x', 'https://x.ru/', "https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=\n&mid=1"];
    }

    public function testLinkAndErid(): void
    {
        $url = 'https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=Kra23dyAk&mid=105263';
        $merchant = new Merchant(new MerchantId(105263), 'superstep.ru', 'https://superstep.ru/', affiliateLink: $url, adMarking: 'Реклама. ООО «ИНТЕРМОДЕ», https://superstep.ru/. erid Kra23dyAk');

        self::assertSame($url, $merchant->link()?->url());
        self::assertSame($url, $merchant->affiliateLink());
        self::assertSame('Kra23dyAk', $merchant->erid());
        self::assertSame($url . '&sub_id=site', $merchant->link()->withSubId('site')->url());

        $link = AffiliateLink::fromString('https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=2SDnjLINK01&mid=1');
        $fromLink = new Merchant(new MerchantId(1), 'x', 'https://x.ru/', affiliateLink: $link);
        self::assertSame($link, $fromLink->link());
        self::assertSame('2SDnjLINK01', $fromLink->erid());

        $marked = new Merchant(new MerchantId(1), 'x', 'https://x.ru/', affiliateLink: $link, adMarking: 'Реклама. erid 2SDnjMARK01');
        self::assertSame('2SDnjMARK01', $marked->erid());

        $eridanus = new Merchant(new MerchantId(1), 'x', 'https://eridanus.ru/', affiliateLink: $link, adMarking: 'Реклама. Рекламодатель ООО Eridanus ИНН 7700000000, https://eridanus.ru/.');
        self::assertSame('2SDnjLINK01', $eridanus->erid());

        $aliexpress = new Merchant(new MerchantId(82012), 'AliExpress', 'http://aliexpress.com/', affiliateLink: 'https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=&mid=82012');
        self::assertNull($aliexpress->erid());
    }

    public function testEridWithoutLink(): void
    {
        $merchant = new Merchant(new MerchantId(101124), 'x', 'https://aliexpress.ru/', adMarking: 'Реклама. ООО "АЛИБАБА.КОМ (РУ)", https://aliexpress.ru/. erid Kra2449mN');

        self::assertNull($merchant->link());
        self::assertSame('Kra2449mN', $merchant->erid());
        self::assertNull((new Merchant(new MerchantId(1), 'x', 'https://x.ru/'))->erid());
    }

    public function testInvalidLinkMessageHasMerchantIdButNoLink(): void
    {
        try {
            new Merchant(new MerchantId(7), 'x', 'https://x.ru/', affiliateLink: 'https://sf.gdeslon.ru/cf/0a1b2c3d4e?mid=7 ');
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Партнёрская ссылка магазина 7', $e->getMessage());
            self::assertStringNotContainsString('0a1b2c3d4e', $e->getMessage());
        }
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

    public function testHttpLogoIsKept(): void
    {
        self::assertSame('https://www.gdeslon.ru/system/logos/1.png', (new Merchant(new MerchantId(1), 'x', 'https://x.ru/', logoUrl: 'https://www.gdeslon.ru/system/logos/1.png'))->logoUrl());
    }

    public function testEmptyOptionalTextsBecomeNull(): void
    {
        $merchant = new Merchant(new MerchantId(1), 'x', 'https://x.ru/', commissionSummary: ' ', adMarking: '');

        self::assertNull($merchant->commissionSummary());
        self::assertNull($merchant->adMarking());
    }
}
