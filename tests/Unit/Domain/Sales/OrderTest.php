<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Sales;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderId;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OrderTest extends TestCase
{
    public function testFullOrder(): void
    {
        $transition = new \DateTimeImmutable('2026-09-01T10:00:00+03:00');
        $created = new \DateTimeImmutable('2026-09-01T10:15:00+03:00');
        $updated = new \DateTimeImmutable('2026-09-20T00:00:00+03:00');
        $confirmed = new \DateTimeImmutable('2026-09-20T00:00:00+03:00');
        $accrued = new \DateTimeImmutable('2026-10-01T00:00:00+03:00');

        $order = new Order(
            id: '81234567',
            merchantId: 2573,
            state: OrderState::Confirmed,
            type: OrderType::Product,
            reward: new Money('150.00', 'RUB'),
            amount: new Money('1500.00', 'RUB'),
            merchantOrderNumber: ' A-1001 ',
            subId: 'blog',
            merchantName: 'Магазин',
            affiliateId: 100500,
            itemCount: 2,
            transitionAt: $transition,
            createdAt: $created,
            lastUpdatedAt: $updated,
            confirmedAt: $confirmed,
            accruedAt: $accrued,
            keywords: 'купить чайник',
        );

        self::assertTrue($order->id()->equals(new OrderId('81234567')));
        self::assertTrue($order->merchantId()->equals(new MerchantId(2573)));
        self::assertSame(OrderState::Confirmed, $order->state());
        self::assertSame(OrderType::Product, $order->type());
        self::assertFalse($order->isLead());
        self::assertSame('150.00 RUB', (string) $order->reward());
        self::assertSame('1500.00 RUB', (string) $order->amount());
        self::assertSame('A-1001', $order->merchantOrderNumber());
        self::assertSame('blog', $order->subId());
        self::assertSame('Магазин', $order->merchantName());
        self::assertSame(100500, $order->affiliateId());
        self::assertSame(2, $order->itemCount());
        self::assertSame($transition, $order->transitionAt());
        self::assertSame($created, $order->createdAt());
        self::assertSame($updated, $order->lastUpdatedAt());
        self::assertSame($confirmed, $order->confirmedAt());
        self::assertSame($accrued, $order->accruedAt());
        self::assertSame('купить чайник', $order->keywords());
        self::assertSame($confirmed, $order->date(OrderDateField::Confirmed));
        self::assertSame($transition, $order->date(OrderDateField::Transition));
        self::assertSame($created, $order->date(OrderDateField::Created));
        self::assertSame($updated, $order->date(OrderDateField::LastUpdated));
        self::assertSame($accrued, $order->date(OrderDateField::Accrued));
    }

    public function testMinimalLead(): void
    {
        $order = new Order(
            id: new OrderId('9'),
            merchantId: new MerchantId(1),
            state: OrderState::New,
            type: OrderType::Lead,
            reward: new Money('0', 'RUB'),
            merchantOrderNumber: '  ',
            subId: '',
            merchantName: '',
            keywords: " \n",
        );

        self::assertTrue($order->isLead());
        self::assertNull($order->amount());
        self::assertNull($order->merchantOrderNumber());
        self::assertNull($order->subId());
        self::assertNull($order->merchantName());
        self::assertNull($order->affiliateId());
        self::assertNull($order->itemCount());
        self::assertNull($order->createdAt());
        self::assertNull($order->date(OrderDateField::Accrued));
        self::assertNull($order->keywords());
    }

    /**
     * @param \Closure(): Order $create
     */
    #[DataProvider('invalidOrders')]
    public function testInvariants(\Closure $create, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /**
     * @return iterable<string, array{\Closure(): Order, string}>
     */
    public static function invalidOrders(): iterable
    {
        yield 'сумма в другой валюте' => [
            static fn (): Order => new Order('1', 1, OrderState::New, OrderType::Product, new Money('1', 'RUB'), amount: new Money('10', 'USD')),
            'USD',
        ];
        yield 'товаров меньше нуля' => [
            static fn (): Order => new Order('1', 1, OrderState::New, OrderType::Product, new Money('1', 'RUB'), itemCount: -1),
            'товаров',
        ];
        yield 'ID вебмастера ноль' => [
            static fn (): Order => new Order('1', 1, OrderState::New, OrderType::Product, new Money('1', 'RUB'), affiliateId: 0),
            'вебмастера',
        ];
        yield 'пустой ID' => [
            static fn (): Order => new Order(' ', 1, OrderState::New, OrderType::Product, new Money('1', 'RUB')),
            'ID заказа',
        ];
        yield 'магазин 0' => [
            static fn (): Order => new Order('1', 0, OrderState::New, OrderType::Product, new Money('1', 'RUB')),
            'магазина',
        ];
    }
}
