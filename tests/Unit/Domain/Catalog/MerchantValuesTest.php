<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTariff;
use Webreboot\GdeSlon\Domain\Catalog\MerchantCategory;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\RateType;
use Webreboot\GdeSlon\Domain\Catalog\Tariff;
use Webreboot\GdeSlon\Domain\Catalog\TrafficType;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class MerchantValuesTest extends TestCase
{
    public function testMerchantId(): void
    {
        $id = new MerchantId(102317);

        self::assertSame(102317, $id->value());
        self::assertSame('102317', (string) $id);
        self::assertTrue($id->equals(new MerchantId(102317)));
        self::assertFalse($id->equals(new MerchantId(82012)));
    }

    #[DataProvider('nonPositive')]
    public function testMerchantIdIsPositive(int $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MerchantId($value);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositive(): iterable
    {
        yield 'ноль' => [0];
        yield 'отрицательный' => [-1];
    }

    public function testMerchantCategory(): void
    {
        $category = new MerchantCategory(50, ' Обучение ');

        self::assertSame(50, $category->id());
        self::assertSame('Обучение', $category->name());
    }

    public function testMerchantCategoryInvariants(): void
    {
        $this->assertInvalid(static fn () => new MerchantCategory(0, 'Обучение'));
        self::assertNull((new MerchantCategory(50, '  '))->name(), 'пустое название — не ошибка');
        self::assertNull((new MerchantCategory(50, null))->name());
    }

    public function testTrafficType(): void
    {
        $type = new TrafficType(' Cashback ', true);

        self::assertSame('Cashback', $type->name());
        self::assertTrue($type->isAllowed());
        self::assertFalse((new TrafficType('Дорвеи', false))->isAllowed());
        $this->assertInvalid(static fn () => new TrafficType('', true));
    }

    #[DataProvider('validRates')]
    public function testTariffKeepsRateAsInApi(string $rate): void
    {
        self::assertSame($rate, self::tariff(rate: $rate)->rate());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validRates(): iterable
    {
        foreach (['10.31', '200.0', '0', '5'] as $rate) {
            yield $rate => [$rate];
        }
    }

    #[DataProvider('invalidRates')]
    public function testTariffRejectsNonDecimalRate(string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::tariff(rate: $rate);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRates(): iterable
    {
        foreach (['пусто' => '', 'минус' => '-1', 'запятая' => '1,5', 'экспонента' => '1e3', 'пробел' => ' 1', 'буквы' => 'abc', 'перевод строки' => "1.5\n"] as $case => $rate) {
            yield $case => [$rate];
        }
    }

    public function testTariff(): void
    {
        $tariff = new Tariff('1388', 'Оплаченный заказ', RateType::Percent, '10.3', ['coupons', 'promocodes'], ['Наушники', 'Apple']);

        self::assertSame('1388', $tariff->id());
        self::assertSame('Оплаченный заказ', $tariff->title());
        self::assertSame(RateType::Percent, $tariff->rateType());
        self::assertTrue($tariff->isPercent());
        self::assertSame(['coupons', 'promocodes'], $tariff->trafficCategories());
        self::assertSame(['Наушники', 'Apple'], $tariff->productCategories());

        $fixed = self::tariff(title: '', type: RateType::Fixed);
        self::assertNull($fixed->title());
        self::assertFalse($fixed->isPercent());
        self::assertSame([], $fixed->trafficCategories());
    }

    public function testTariffInvariants(): void
    {
        $this->assertInvalid(static fn () => new Tariff('', null, RateType::Percent, '1.0'));
        $this->assertInvalid(static fn () => new Tariff('1', null, RateType::Percent, '1.0', ['coupons', '']));
        $this->assertInvalid(static fn () => new Tariff('1', null, RateType::Percent, '1.0', [], [' ']));
    }

    public function testRateType(): void
    {
        self::assertSame(RateType::Percent, RateType::from('percent'));
        self::assertSame(RateType::Fixed, RateType::from('fixed'));
    }

    public function testCategoryTariff(): void
    {
        $tariff = new CategoryTariff(1000, 'iPad Pro M4 (2024)', true, '2.93');

        self::assertSame(1000, $tariff->merchantCategoryId());
        self::assertSame('iPad Pro M4 (2024)', $tariff->name());
        self::assertTrue($tariff->isPercent());
        self::assertSame('2.93', $tariff->rate());

        $this->assertInvalid(static fn () => new CategoryTariff(0, 'x', true, '1.0'));
        self::assertNull((new CategoryTariff(6, '', true, '1.07'))->name());
        self::assertNull((new CategoryTariff(6, ' ', true, '1.07'))->name());
        $this->assertInvalid(static fn () => new CategoryTariff(1000, 'x', true, '1,0'));
    }

    private static function tariff(string $rate = '1.0', ?string $title = 'Заказ', RateType $type = RateType::Percent): Tariff
    {
        return new Tariff('1', $title, $type, $rate);
    }

    private function assertInvalid(\Closure $create): void
    {
        try {
            $create();
            self::fail('Ожидалось InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
