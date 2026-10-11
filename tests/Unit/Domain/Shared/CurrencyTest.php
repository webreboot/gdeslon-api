<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Shared\Currency;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class CurrencyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function codes(): iterable
    {
        yield 'заглавные' => ['USD', 'USD'];
        yield 'строчные' => ['usd', 'USD'];
        yield 'смешанные' => ['eUr', 'EUR'];
        yield 'пробелы по краям' => [" kzt\n", 'KZT'];
        yield 'rub строчными' => ['rub', 'RUB'];
    }

    #[DataProvider('codes')]
    public function testNormalizesCase(string $code, string $expected): void
    {
        self::assertSame($expected, Currency::normalize($code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacyRur(): iterable
    {
        yield 'RUR' => ['RUR'];
        yield 'rur' => ['rur'];
        yield 'Rur' => ['Rur'];
        yield 'с пробелами' => [' RUR '];
    }

    #[DataProvider('legacyRur')]
    public function testLegacyRurBecomesRub(string $code): void
    {
        self::assertSame('RUB', Currency::normalize($code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'пусто' => [''];
        yield 'пробелы' => ['   '];
        yield 'две буквы' => ['RU'];
        yield 'четыре буквы' => ['RUBL'];
        yield 'кириллица' => ['РУБ'];
        yield 'цифры' => ['643'];
        yield 'пробел внутри' => ['R UB'];
        yield 'перевод строки внутри' => ["RU\nB"];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);

        Currency::normalize($code);
    }
}
