<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Sales;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderId;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OrderListTest extends TestCase
{
    public function testListKeepsApiOrder(): void
    {
        $criteria = new OrderCriteria(days: 7);
        $list = new OrderList($criteria, [self::order('3', OrderState::Paid), self::order('1'), self::order('2', OrderState::Confirmed)], ['заказ #4: битый']);

        self::assertSame($criteria, $list->criteria());
        self::assertCount(3, $list);
        self::assertFalse($list->isEmpty());
        self::assertSame(['3', '1', '2'], array_map(static fn (Order $o): string => $o->id()->value(), $list->all()));
        self::assertSame(['3', '1', '2'], array_map(static fn (Order $o): string => $o->id()->value(), iterator_to_array($list)));
        self::assertSame(['заказ #4: битый'], $list->skipped());
        self::assertSame('1', $list->find('1')?->id()->value());
        self::assertSame('2', $list->find(new OrderId('2'))?->id()->value());
        self::assertNull($list->find('404'));
        self::assertSame(
            ['3', '2'],
            array_map(static fn (Order $o): string => $o->id()->value(), $list->filter(static fn (Order $o): bool => $o->state() !== OrderState::New)),
        );
    }

    public function testEmptyList(): void
    {
        $list = new OrderList(new OrderCriteria(), []);

        self::assertTrue($list->isEmpty());
        self::assertCount(0, $list);
        self::assertSame([], $list->skipped());
    }

    public function testDuplicateIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('81234567');

        new OrderList(new OrderCriteria(), [self::order('81234567'), self::order('81234567')]);
    }

    private static function order(string $id, OrderState $state = OrderState::New): Order
    {
        return new Order($id, 1, $state, OrderType::Product, new Money('1', 'RUB'));
    }
}
