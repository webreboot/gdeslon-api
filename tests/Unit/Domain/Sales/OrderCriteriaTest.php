<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Sales;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OrderCriteriaTest extends TestCase
{
    public function testDefaults(): void
    {
        $criteria = new OrderCriteria();

        self::assertSame(OrderDateField::Created, $criteria->dateField());
        self::assertNull($criteria->until(), 'null — «сегодня» по часам адаптера');
        self::assertSame(30, $criteria->days());
        self::assertSame(OrderCriteria::DEFAULT_DAYS, $criteria->days());
        self::assertNull($criteria->merchant());
        self::assertSame([], $criteria->states());
        self::assertNull($criteria->type());
        self::assertNull($criteria->subId());
    }

    public function testAllFilters(): void
    {
        $criteria = new OrderCriteria(
            dateField: OrderDateField::Confirmed,
            until: '2026-09-30',
            days: 7,
            merchant: 2573,
            states: [3, OrderState::Paid, 3],
            type: OrderType::Lead,
            subId: ' abc ',
        );

        self::assertSame(OrderDateField::Confirmed, $criteria->dateField());
        self::assertSame('2026-09-30', $criteria->until());
        self::assertSame(7, $criteria->days());
        self::assertSame(2573, $criteria->merchant()?->value());
        self::assertSame([OrderState::Confirmed, OrderState::Paid], $criteria->states());
        self::assertSame(OrderType::Lead, $criteria->type());
        self::assertSame('abc', $criteria->subId());
    }

    public function testMerchantIdAndIntAreEquivalent(): void
    {
        self::assertSame(2573, (new OrderCriteria(merchant: new MerchantId(2573)))->merchant()?->value());
    }

    public function testDaysBounds(): void
    {
        self::assertSame(1, (new OrderCriteria(days: 1))->days());
        self::assertSame(OrderCriteria::MAX_DAYS, (new OrderCriteria(days: OrderCriteria::MAX_DAYS))->days());
        self::assertSame(3660, OrderCriteria::MAX_DAYS);
    }

    public function testUntilFromDateTimeKeepsItsOwnDate(): void
    {
        $evening = new \DateTimeImmutable('2026-10-07 23:30', new \DateTimeZone('+03:00'));

        self::assertSame('2026-10-07', (new OrderCriteria(until: $evening))->until(), 'без перевода в UTC');
        self::assertSame('2026-10-07', (new OrderCriteria(until: new \DateTime('2026-10-07 00:00:01')))->until());
    }

    public function testCyrillicSubId(): void
    {
        self::assertSame('тест', (new OrderCriteria(subId: 'тест'))->subId());
    }

    /**
     * @param \Closure(): OrderCriteria $create
     */
    #[DataProvider('invalidCriteria')]
    public function testInvalidCriteria(\Closure $create, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /**
     * @return iterable<string, array{\Closure(): OrderCriteria, string}>
     */
    public static function invalidCriteria(): iterable
    {
        yield 'days 0' => [static fn (): OrderCriteria => new OrderCriteria(days: 0), 'days'];
        yield 'days отрицательный' => [static fn (): OrderCriteria => new OrderCriteria(days: -1), 'days'];
        yield 'days больше максимума' => [static fn (): OrderCriteria => new OrderCriteria(days: OrderCriteria::MAX_DAYS + 1), 'days'];
        yield 'несуществующая дата' => [static fn (): OrderCriteria => new OrderCriteria(until: '2026-13-45'), '2026-13-45'];
        yield '31 февраля' => [static fn (): OrderCriteria => new OrderCriteria(until: '2026-02-31'), '2026-02-31'];
        yield 'дата по-русски' => [static fn (): OrderCriteria => new OrderCriteria(until: '07.10.2026'), '07.10.2026'];
        yield 'дата со временем' => [static fn (): OrderCriteria => new OrderCriteria(until: '2026-10-07 12:00'), '2026-10-07 12:00'];
        yield 'дата с переводом строки' => [static fn (): OrderCriteria => new OrderCriteria(until: "2026-10-07\n"), 'Y-m-d'];
        yield 'пустая дата' => [static fn (): OrderCriteria => new OrderCriteria(until: ''), 'Y-m-d'];
        yield 'магазин 0' => [static fn (): OrderCriteria => new OrderCriteria(merchant: 0), 'магазина'];
        yield 'статус 9' => [static fn (): OrderCriteria => new OrderCriteria(states: [9]), 'статус 9'];
        // @phpstan-ignore argument.type (строки из формы или CLI — проверка типа во время выполнения)
        yield 'статус строкой' => [static fn (): OrderCriteria => new OrderCriteria(states: ['3']), 'статус'];
        // @phpstan-ignore argument.type
        yield 'статус null' => [static fn (): OrderCriteria => new OrderCriteria(states: [null]), 'статус'];
        yield 'пустой sub_id' => [static fn (): OrderCriteria => new OrderCriteria(subId: ''), 'sub_id'];
        yield 'sub_id из пробелов' => [static fn (): OrderCriteria => new OrderCriteria(subId: '  '), 'sub_id'];
        yield 'sub_id не UTF-8' => [static fn (): OrderCriteria => new OrderCriteria(subId: "\xff"), 'UTF-8'];
    }
}
