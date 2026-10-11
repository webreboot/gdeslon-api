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

    public function testPlusAddsAmountsOfSameCurrency(): void
    {
        $sum = (new Money('1500.00', 'RUB'))->plus(new Money('200.50', 'RUB'));

        self::assertSame('1700.50', $sum->amount());
        self::assertSame('RUB', $sum->currency());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function sums(): iterable
    {
        yield 'целое и дробь' => ['100', '0.5', '100.5'];
        yield 'разная длина дроби' => ['1.5', '2.25', '3.75'];
        yield 'хвостовые нули сохраняются' => ['1.50', '2.5', '4.00'];
        yield 'разрядность нулевого слагаемого' => ['100', '0.00', '100.00'];
        yield 'ловушка float' => ['0.1', '0.2', '0.3'];
        yield 'нули' => ['0', '0', '0'];
        yield 'ноль с точкой и ноль' => ['0.00', '0', '0.00'];
        yield 'ноль и целое' => ['0', '5', '5'];
        yield 'перенос через точку' => ['0.99', '0.01', '1.00'];
        yield 'перенос через все разряды' => ['999.99', '0.01', '1000.00'];
        yield 'перенос через десять знаков дроби' => ['0.0000000001', '0.9999999999', '1.0000000000'];
        yield 'новый разряд' => ['9', '1', '10'];
        yield 'ведущие нули целой части' => ['007', '1', '8'];
        yield 'ведущие нули до точки' => ['000.50', '0', '0.50'];
        yield 'за PHP_INT_MAX' => ['9223372036854775807', '1', '9223372036854775808'];
        yield 'за PHP_INT_MAX с копейками' => ['99999999999999999999.99', '0.01', '100000000000000000000.00'];
        yield 'сорокозначные' => [
            '1234567890123456789012345678901234567890',
            '9876543210987654321098765432109876543210',
            '11111111101111111110111111111011111111100',
        ];
    }

    #[DataProvider('sums')]
    public function testPlusUsesLongerFractionScale(string $left, string $right, string $expected): void
    {
        self::assertSame($expected, (new Money($left, 'RUB'))->plus(new Money($right, 'RUB'))->amount());
    }

    public function testPlusCarriesAcrossPointAndDigits(): void
    {
        self::assertSame('1.00', (new Money('0.99', 'RUB'))->plus(new Money('0.01', 'RUB'))->amount());
        self::assertSame('1000.00', (new Money('999.99', 'RUB'))->plus(new Money('0.01', 'RUB'))->amount());
        self::assertSame('1.0000000000', (new Money('0.0000000001', 'RUB'))->plus(new Money('0.9999999999', 'RUB'))->amount());
        self::assertSame('10', (new Money('9', 'RUB'))->plus(new Money('1', 'RUB'))->amount());
    }

    public function testPlusStripsLeadingZerosOfIntegerPart(): void
    {
        self::assertSame('8', (new Money('007', 'RUB'))->plus(new Money('1', 'RUB'))->amount());
        self::assertSame('0.50', (new Money('000.50', 'RUB'))->plus(new Money('0', 'RUB'))->amount());
    }

    public function testPlusBeyondPhpIntMax(): void
    {
        self::assertSame('9223372036854775808', (new Money((string) PHP_INT_MAX, 'RUB'))->plus(new Money('1', 'RUB'))->amount());
        self::assertSame('100000000000000000000.00', (new Money('99999999999999999999.99', 'RUB'))->plus(new Money('0.01', 'RUB'))->amount());
        self::assertSame(
            '11111111101111111110111111111011111111100',
            (new Money('1234567890123456789012345678901234567890', 'RUB'))->plus(new Money('9876543210987654321098765432109876543210', 'RUB'))->amount(),
        );
    }

    #[DataProvider('sums')]
    public function testPlusIsCommutative(string $left, string $right): void
    {
        $a = new Money($left, 'RUB');
        $b = new Money($right, 'RUB');

        self::assertSame($a->plus($b)->amount(), $b->plus($a)->amount());
    }

    public function testPlusMatchesIntegerArithmeticOnCents(): void
    {
        mt_srand(7);
        for ($i = 0; $i < 200; $i++) {
            $left = mt_rand(0, 10 ** 12);
            $right = mt_rand(0, 10 ** 12);

            $sum = (new Money(self::cents($left), 'RUB'))->plus(new Money(self::cents($right), 'RUB'));

            self::assertSame(self::cents($left + $right), $sum->amount(), self::cents($left) . ' + ' . self::cents($right));
        }
    }

    public function testPlusLeavesOperandsUnchanged(): void
    {
        $left = new Money('1.5', 'RUB');
        $right = new Money('2.25', 'RUB');

        $sum = $left->plus($right);

        self::assertSame('1.5', $left->amount());
        self::assertSame('2.25', $right->amount());
        self::assertNotSame($left, $sum);
        self::assertNotSame($right, $sum);
    }

    #[DataProvider('sums')]
    public function testPlusIsNotLessThanEachOperand(string $left, string $right): void
    {
        $a = new Money($left, 'RUB');
        $b = new Money($right, 'RUB');
        $sum = $a->plus($b);

        self::assertGreaterThanOrEqual(0, $sum->compare($a));
        self::assertGreaterThanOrEqual(0, $sum->compare($b));
    }

    public function testPlusRejectsDifferentCurrencies(): void
    {
        try {
            (new Money('1234.56', 'RUB'))->plus(new Money('7890.12', 'USD'));
            self::fail('Нет исключения');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Суммы в разных валютах: RUB и USD', $e->getMessage());
            self::assertStringNotContainsString('1234', $e->getMessage());
            self::assertStringNotContainsString('7890', $e->getMessage());
        }
    }

    public function testPlusRejectsRurAndRub(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Суммы в разных валютах: RUR и RUB');

        (new Money('1', 'RUR'))->plus(new Money('1', 'RUB'));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function comparisons(): iterable
    {
        yield 'одно значение в разной записи' => ['100', '100.00', 0];
        yield 'длина целой части важнее цифр' => ['10', '9.99', 1];
        yield 'целые одной длины с большой разницей цифр' => ['9', '1', 1];
        yield 'старший разряд решает' => ['100', '500', -1];
        yield 'короткое целое меньше' => ['2', '10', -1];
        yield 'дробь сравнивается по разрядам' => ['1.1', '1.09', 1];
        yield 'меньшая дробь' => ['0.09', '0.1', -1];
        yield 'ведущие нули' => ['007', '7', 0];
        yield 'ведущие и хвостовые нули' => ['00.10', '0.1', 0];
        yield 'нули' => ['0', '0.000', 0];
        yield 'за PHP_INT_MAX' => ['9223372036854775808', '9223372036854775807', 1];
        yield 'неразличимые для float целые' => ['9007199254740993', '9007199254740992', 1];
        yield 'неразличимые для float дроби' => ['0.30000000000000001', '0.3', 1];
        yield 'неразличимая для float единица' => ['1', '1.0000000000000001', -1];
    }

    #[DataProvider('comparisons')]
    public function testCompareReturnsSign(string $left, string $right, int $expected): void
    {
        self::assertSame($expected, (new Money($left, 'RUB'))->compare(new Money($right, 'RUB')));
    }

    #[DataProvider('comparisons')]
    public function testCompareIsAntisymmetric(string $left, string $right, int $expected): void
    {
        self::assertSame(-$expected, (new Money($right, 'RUB'))->compare(new Money($left, 'RUB')));
    }

    public function testCompareRejectsDifferentCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Суммы в разных валютах: RUB и USD');

        (new Money('1', 'RUB'))->compare(new Money('1', 'USD'));
    }

    #[DataProvider('comparisons')]
    public function testIsGreaterThanAndIsLessThan(string $left, string $right, int $expected): void
    {
        $a = new Money($left, 'RUB');
        $b = new Money($right, 'RUB');

        self::assertSame($expected === 1, $a->isGreaterThan($b));
        self::assertSame($expected === -1, $a->isLessThan($b));
    }

    /**
     * @return iterable<string, array{\Closure(Money, Money): bool}>
     */
    public static function orderingPredicates(): iterable
    {
        yield 'isGreaterThan' => [static fn (Money $a, Money $b): bool => $a->isGreaterThan($b)];
        yield 'isLessThan' => [static fn (Money $a, Money $b): bool => $a->isLessThan($b)];
    }

    /**
     * @param \Closure(Money, Money): bool $predicate
     */
    #[DataProvider('orderingPredicates')]
    public function testOrderingPredicatesRejectDifferentCurrencies(\Closure $predicate): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Суммы в разных валютах: RUB и USD');

        $predicate(new Money('2', 'RUB'), new Money('1', 'USD'));
    }

    #[DataProvider('comparisons')]
    public function testEqualsAgreesWithCompare(string $left, string $right, int $expected): void
    {
        $a = new Money($left, 'RUB');
        $b = new Money($right, 'RUB');

        self::assertSame($a->compare($b) === 0, $a->equals($b));
        self::assertSame($expected === 0, $a->equals($b));
    }

    public function testEqualsWithDifferentCurrencyIsFalseWithoutException(): void
    {
        self::assertFalse((new Money('100', 'RUB'))->equals(new Money('100', 'USD')));
        self::assertFalse((new Money('100', 'RUR'))->equals(new Money('100', 'RUB')));
    }

    private static function cents(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
