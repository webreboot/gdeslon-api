<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OfferTest extends TestCase
{
    private const LINK = 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=107054&goto=https%3A%2F%2Fx.ru&erid=abc';

    public function testMinimalOfferDefaults(): void
    {
        // ID оффера в API — 18–20 цифр, больше PHP_INT_MAX: хранится строкой
        $offer = new Offer('14367733380732236000', new MerchantId(107054), 'Пакет подарочный', new Money('100', 'RUR'), self::LINK);

        self::assertSame('14367733380732236000', $offer->id());
        self::assertSame(107054, $offer->merchantId()->value());
        self::assertSame('Пакет подарочный', $offer->name());
        self::assertSame('100 RUR', (string) $offer->price());
        self::assertSame(self::LINK, $offer->affiliateLink());
        self::assertNull($offer->oldPrice());
        self::assertNull($offer->charge());
        self::assertNull($offer->article());
        self::assertNull($offer->categoryId());
        self::assertTrue($offer->isAvailable());
        self::assertNull($offer->picture());
        self::assertNull($offer->thumbnail());
        self::assertNull($offer->originalPicture());
        self::assertNull($offer->description());
        self::assertNull($offer->vendor());
        self::assertNull($offer->model());
        self::assertNull($offer->productUrl());
        self::assertNull($offer->adMarking());
    }

    #[DataProvider('invalidOffers')]
    public function testInvariants(string $id, string $name, string $link): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Offer($id, new MerchantId(1), $name, new Money('1', 'RUR'), $link);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidOffers(): iterable
    {
        yield 'пустое имя' => ['1', '  ', self::LINK];
        yield 'пустой id' => ['', 'x', self::LINK];
        yield 'id с буквами' => ['abc', 'x', self::LINK];
        yield 'отрицательный id' => ['-1', 'x', self::LINK];
        yield 'id с переводом строки' => ["1\n", 'x', self::LINK];
        yield 'имя из неразрывных пробелов' => ['1', "\u{00A0} \u{00A0}", self::LINK];
        yield 'пустая ссылка' => ['1', 'x', ''];
        yield 'ссылка без схемы' => ['1', 'x', 'af.gdeslon.ru/cm/x'];
        yield 'ссылка ftp' => ['1', 'x', 'ftp://x'];
    }

    public function testParkedDomainLinkIsAccepted(): void
    {
        $offer = new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUR'), 'http://example.com/cm/0a1b2c3d4e/?mid=1');

        self::assertSame('http://example.com/cm/0a1b2c3d4e/?mid=1', $offer->affiliateLink());
    }

    public function testPricesShareCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUR'), self::LINK, oldPrice: new Money('2', 'USD'));
    }

    public function testChargeSharesCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUR'), self::LINK, charge: new Money('0.5', 'USD'));
    }

    public function testTextsAreNormalized(): void
    {
        $offer = new Offer(
            '1',
            new MerchantId(1),
            '  Платье «Анабель» ',
            new Money('1', 'RUR'),
            self::LINK,
            description: "  **Описание**\r\n- пункт  ",
            vendor: ' ',
            model: '',
            adMarking: '   ',
        );

        self::assertSame('Платье «Анабель»', $offer->name());
        self::assertSame("**Описание**\n- пункт", $offer->description());
        self::assertNull($offer->vendor());
        self::assertNull($offer->model());
        self::assertNull($offer->adMarking());
    }
}
