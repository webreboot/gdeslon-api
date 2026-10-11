<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\OfferKey;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OfferKeyTest extends TestCase
{
    public function testValues(): void
    {
        $key = new OfferKey(new MerchantId(107054), '578237');

        self::assertSame(107054, $key->merchantId()->value());
        self::assertSame('578237', $key->article());
        self::assertSame('107054:578237', (string) $key);
        self::assertSame('107054:578237', (string) OfferKey::fromString('107054:578237'));
    }

    public function testEquals(): void
    {
        $key = new OfferKey(new MerchantId(107054), '578237');

        self::assertTrue($key->equals(new OfferKey(new MerchantId(107054), '578237')));
        self::assertTrue($key->equals(OfferKey::fromString('107054:578237')));
        self::assertFalse($key->equals(new OfferKey(new MerchantId(113068), '578237')));
        self::assertFalse($key->equals(new OfferKey(new MerchantId(107054), '578238')));
        self::assertFalse((new OfferKey(new MerchantId(1), 'p67ceos'))->equals(new OfferKey(new MerchantId(1), 'P67CEOS')));
    }

    public function testArticleKeptAsIs(): void
    {
        $key = new OfferKey(new MerchantId(113906), 'Шлем кроссовый GD-01');

        self::assertSame('Шлем кроссовый GD-01', $key->article());
        self::assertSame('113906:Шлем кроссовый GD-01', (string) $key);
        self::assertSame('a:b', (new OfferKey(new MerchantId(1), 'a:b'))->article());
        self::assertSame(' 002 ', (new OfferKey(new MerchantId(1), ' 002 '))->article());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankArticles(): iterable
    {
        yield 'пусто' => [''];
        yield 'пробелы' => ['   '];
        yield 'перевод строки' => ["\n"];
        yield 'неразрывный пробел' => ["\u{00A0}"];
    }

    #[DataProvider('blankArticles')]
    public function testBlankArticleRejected(string $article): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OfferKey(new MerchantId(1), $article);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'пусто' => [''];
        yield 'без двоеточия' => ['107054'];
        yield 'без артикула' => ['107054:'];
        yield 'пробельный артикул' => ['107054: '];
        yield 'без магазина' => [':578237'];
        yield 'магазин ноль' => ['0:578237'];
        yield 'магазин с буквами' => ['abc:578237'];
        yield 'магазин с пробелом' => [' 107054:578237'];
        yield 'отрицательный магазин' => ['-1:578237'];
        yield 'слишком длинный магазин' => ['1234567890123456789:578237'];
    }

    #[DataProvider('invalidKeys')]
    public function testFromStringInvalid(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        OfferKey::fromString($value);
    }

    public function testFromStringSplitsOnFirstColon(): void
    {
        $key = OfferKey::fromString('1:a:b');

        self::assertSame(1, $key->merchantId()->value());
        self::assertSame('a:b', $key->article());
        self::assertSame('1:a:b', (string) $key);
        self::assertSame('113906:Шлем кроссовый GD-01', (string) OfferKey::fromString('113906:Шлем кроссовый GD-01'));
    }
}
