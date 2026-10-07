<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function amounts(): iterable
    {
        yield 'целое' => ['100', false];
        yield 'копейки' => ['1999.99', false];
        yield 'ноль' => ['0', true];
        yield 'ноль с точкой' => ['0.00', true];
    }

    #[DataProvider('amounts')]
    public function testAmountAsInApi(string $amount, bool $zero): void
    {
        $money = new Money($amount, 'RUR');

        self::assertSame($amount, $money->amount());
        self::assertSame('RUR', $money->currency());
        self::assertSame($zero, $money->isZero());
        self::assertSame($amount . ' RUR', (string) $money);
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidValues(string $amount, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money($amount, $currency);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        foreach (['пусто' => '', 'пробел' => ' 1', 'запятая' => '1,5', 'минус' => '-1', 'экспонента' => '1e3', 'без целой части' => '.5', 'точка в конце' => '1.', 'буквы' => 'abc', 'перевод строки' => "100\n"] as $case => $amount) {
            yield 'сумма: ' . $case => [$amount, 'RUR'];
        }
        foreach (['строчные' => 'rur', 'две буквы' => 'RU', 'пусто' => '', 'перевод строки' => "RUR\n"] as $case => $currency) {
            yield 'валюта: ' . $case => ['1', $currency];
        }
    }

    public function testEqualsComparesValue(): void
    {
        self::assertTrue((new Money('100', 'RUR'))->equals(new Money('100.0', 'RUR')));
        self::assertTrue((new Money('100.00', 'RUR'))->equals(new Money('100', 'RUR')));
        self::assertTrue((new Money('3.6', 'RUR'))->equals(new Money('3.60', 'RUR')));
        self::assertFalse((new Money('100', 'RUR'))->equals(new Money('100.01', 'RUR')));
        self::assertFalse((new Money('100', 'RUR'))->equals(new Money('100', 'USD')));
    }
}
