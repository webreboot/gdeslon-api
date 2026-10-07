<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Sales;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Sales\OrderMapper;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class OrderMapperTest extends TestCase
{
    private const FORMAT = 'Y-m-d\TH:i:s.uP';

    public function testEmptyResponse(): void
    {
        $list = self::map(Fixtures::json('orders/orders-empty.json'));

        self::assertTrue($list->isEmpty());
        self::assertSame([], $list->skipped());
    }

    public function testSyntheticResponse(): void
    {
        $criteria = new OrderCriteria(days: 7);
        $list = (new OrderMapper())->toList(Fixtures::json('orders/orders-synthetic.json'), $criteria);

        self::assertSame($criteria, $list->criteria());
        self::assertSame([], $list->skipped());
        self::assertSame(['81234567', '81234568', '81234569', '81234570'], array_map(static fn (Order $o): string => $o->id()->value(), $list->all()));
    }

    public function testProductOrder(): void
    {
        $order = self::order('81234567');

        self::assertSame(2573, $order->merchantId()->value());
        self::assertSame('Магазин Пример', $order->merchantName());
        self::assertSame('A-1001', $order->merchantOrderNumber());
        self::assertSame('blog', $order->subId());
        self::assertSame(100500, $order->affiliateId());
        self::assertSame(OrderState::Confirmed, $order->state());
        self::assertSame(OrderType::Product, $order->type());
        self::assertSame('150.00', $order->reward()->amount());
        self::assertSame('RUB', $order->reward()->currency(), 'валюта приводится к верхнему регистру');
        self::assertSame('1500.00 RUB', (string) $order->amount());
        self::assertSame(2, $order->itemCount());
        self::assertSame('2026-09-01T10:00:00.123456+03:00', $order->transitionAt()?->format(self::FORMAT));
        self::assertSame('2026-09-01T10:15:00.000000+03:00', $order->createdAt()?->format(self::FORMAT));
        self::assertSame('2026-09-20T09:00:00.000000+00:00', $order->lastUpdatedAt()?->format(self::FORMAT));
        self::assertSame('2026-09-20T09:00:00.000000+00:00', $order->confirmedAt()?->format(self::FORMAT));
        self::assertNull($order->accruedAt());
        self::assertSame('купить чайник', $order->keywords());
    }

    public function testLead(): void
    {
        $order = self::order('81234568');

        self::assertTrue($order->isLead());
        self::assertSame(OrderState::New, $order->state());
        self::assertNull($order->amount());
        self::assertNull($order->itemCount());
        self::assertNull($order->merchantOrderNumber());
        self::assertNull($order->subId());
        self::assertNull($order->keywords(), 'пустая строка — null');
        self::assertSame('300.00 RUB', (string) $order->reward());
    }

    public function testOtherTypesAreTolerated(): void
    {
        $order = self::order('81234569');

        self::assertSame(107054, $order->merchantId()->value());
        self::assertSame(100500, $order->affiliateId());
        self::assertSame('55501', $order->merchantOrderNumber());
        self::assertSame('123', $order->subId());
        self::assertSame(OrderState::Paid, $order->state());
        self::assertSame(OrderType::Product, $order->type());
        self::assertSame('99.9', $order->reward()->amount());
        self::assertSame('999', $order->amount()?->amount());
        self::assertSame(1, $order->itemCount());
        self::assertSame('чайник', $order->keywords(), '\u-экранирование');
    }

    public function testDatesWithoutTimezoneAreMoscow(): void
    {
        $order = self::order('81234569');

        self::assertSame('2026-09-03T00:00:00.000000+03:00', $order->transitionAt()?->format(self::FORMAT));
        self::assertSame('2026-09-03T08:30:00.000000+03:00', $order->createdAt()?->format(self::FORMAT));
        self::assertSame('2026-09-03T08:30:00.500000+03:00', $order->lastUpdatedAt()?->format(self::FORMAT));
        self::assertSame('2026-09-04T08:30:00.000000+03:00', $order->confirmedAt()?->format(self::FORMAT));
        self::assertSame('2026-10-01T00:00:00.000000+00:00', $order->accruedAt()?->format(self::FORMAT));
    }

    public function testFloatAmountDoesNotDependOnSerializePrecision(): void
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');
        try {
            $order = self::map([self::valid('1', ['partner_payment' => 99.9, 'order_payment' => 1000.0])])->all()[0];
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }

        self::assertSame('99.9', $order->reward()->amount());
        self::assertSame('1000', $order->amount()?->amount());
        self::assertSame('0.0000001', self::map([self::valid('1', ['partner_payment' => 1.0E-7])])->all()[0]->reward()->amount());
    }

    public function testSmallFloatAmountsKeepExactValue(): void
    {
        foreach (['17', '-1'] as $precision) {
            $previous = ini_get('serialize_precision');
            ini_set('serialize_precision', $precision);
            try {
                $list = self::map([
                    self::valid('1', ['partner_payment' => 0.05 + 0.01]),
                    self::valid('2', ['partner_payment' => 0.1 * 0.07]),
                    self::valid('3', ['partner_payment' => 0.0]),
                ]);
            } finally {
                ini_set('serialize_precision', (string) $previous);
            }

            self::assertSame([], $list->skipped());
            self::assertSame('0.060000000000000005', $list->find('1')?->reward()->amount());
            self::assertSame('0.007000000000000001', $list->find('2')?->reward()->amount());
            self::assertSame('0', $list->find('3')?->reward()->amount());
        }
    }

    public function testUnknownFieldsAreIgnoredAndAmountsTrimmed(): void
    {
        $order = self::order('81234570');

        self::assertSame('99.90', $order->reward()->amount());
        self::assertSame('тест', $order->subId());
        self::assertSame(OrderState::Pending, $order->state());
        self::assertNull($order->transitionAt());
    }

    #[DataProvider('brokenOrders')]
    public function testBrokenOrderIsSkipped(mixed $record, string $reason): void
    {
        $list = self::map([self::valid('1'), $record, self::valid('3')]);

        self::assertSame(['1', '3'], array_map(static fn (Order $o): string => $o->id()->value(), $list->all()), 'соседние заказы остались');
        self::assertCount(1, $list->skipped());
        self::assertStringStartsWith('заказ #2', $list->skipped()[0]);
        self::assertStringContainsString($reason, $list->skipped()[0]);
        self::assertStringNotContainsString('секрет-сабайди', $list->skipped()[0], 'значение sub_id в причину не попадает');
        self::assertStringNotContainsString('секретные слова', $list->skipped()[0], 'значение keywords в причину не попадает');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function brokenOrders(): iterable
    {
        yield 'запись не объект' => ['строка', 'не объект'];
        yield 'запись — список' => [[1, 2], 'не объект'];
        yield 'нет ID' => [self::valid('2', ['gdeslon_order_id' => null]), 'gdeslon_order_id'];
        yield 'пустой ID' => [self::valid('2', ['gdeslon_order_id' => ' ']), 'gdeslon_order_id'];
        yield 'ID ноль' => [self::valid('2', ['gdeslon_order_id' => 0]), 'gdeslon_order_id'];
        yield 'нет магазина' => [self::valid('2', ['merchant_id' => null]), 'merchant_id'];
        yield 'магазин 0' => [self::valid('2', ['merchant_id' => 0]), 'магазина'];
        yield 'магазин буквами' => [self::valid('2', ['merchant_id' => 'abc']), 'merchant_id'];
        yield 'неизвестный статус' => [self::valid('2', ['state' => 7]), 'state: неизвестный статус 7'];
        yield 'статус буквами' => [self::valid('2', ['state' => 'confirmed']), 'state'];
        yield 'неизвестный тип' => [self::valid('2', ['type' => 2]), 'type'];
        yield 'отрицательное вознаграждение' => [self::valid('2', ['partner_payment' => '-10']), 'partner_payment'];
        yield 'вознаграждение с запятой' => [self::valid('2', ['partner_payment' => '10,5']), 'partner_payment'];
        yield 'вознаграждение с экспонентой' => [self::valid('2', ['partner_payment' => 1.0E+16]), 'partner_payment'];
        yield 'вознаграждение bool' => [self::valid('2', ['partner_payment' => true]), 'partner_payment'];
        yield 'нет вознаграждения' => [self::valid('2', ['partner_payment' => null]), 'partner_payment'];
        yield 'сумма массивом' => [self::valid('2', ['order_payment' => [1]]), 'order_payment'];
        yield 'отрицательная сумма int' => [self::valid('2', ['order_payment' => -5]), 'order_payment'];
        yield 'нет валюты' => [self::valid('2', ['currency' => null]), 'currency'];
        yield 'валюта словом' => [self::valid('2', ['currency' => 'рубль']), 'currency'];
        yield 'валюта кодом' => [self::valid('2', ['currency' => 643]), 'currency'];
        yield 'дата словом' => [self::valid('2', ['created_at' => 'вчера']), 'created_at'];
        yield 'несуществующая дата' => [self::valid('2', ['confirmed_at' => '2026-02-31']), 'confirmed_at'];
        yield 'дата числом' => [self::valid('2', ['accrued_at' => 1759276800]), 'accrued_at'];
        yield 'товаров меньше нуля' => [self::valid('2', ['items_in_order' => -1]), 'товаров'];
        yield 'товаров буквами' => [self::valid('2', ['items_in_order' => 'два']), 'items_in_order'];
        yield 'вебмастер 0' => [self::valid('2', ['affiliate_id' => 0]), 'вебмастера'];
        yield 'keywords массивом' => [self::valid('2', ['keywords' => ['секретные слова']]), 'keywords'];
        yield 'sub_id объектом' => [self::valid('2', ['sub_id' => ['a' => 'секрет-сабайди']]), 'sub_id'];
    }

    public function testDuplicateIdKeepsFirst(): void
    {
        $list = self::map([self::valid('1', ['state' => 3]), self::valid('1', ['state' => 1]), self::valid('2')]);

        self::assertSame(['1', '2'], array_map(static fn (Order $o): string => $o->id()->value(), $list->all()));
        self::assertSame(OrderState::Confirmed, $list->find('1')?->state());
        self::assertCount(1, $list->skipped());
        self::assertStringContainsString('повтор', $list->skipped()[0]);
    }

    public function testAllOrdersBrokenIsBrokenDocument(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('ни один заказ не разобран (2), первый: заказ #1');

        self::map([self::valid('1', ['state' => 9]), self::valid('2', ['currency' => 643])]);
    }

    #[DataProvider('notLists')]
    public function testNotListIsBrokenDocument(mixed $payload, string $type): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage($type);

        self::map($payload);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function notLists(): iterable
    {
        yield 'обёртка с results' => [['results' => []], 'объект'];
        yield 'ошибка detail' => [['detail' => 'x'], 'объект'];
        yield 'строка' => ['x', 'string'];
        yield 'null' => [null, 'null'];
        yield 'число' => [1, 'int'];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function valid(string $id, array $override = []): array
    {
        return array_replace([
            'gdeslon_order_id' => (int) $id,
            'merchant_order_id' => 'N-' . $id,
            'sub_id' => 'секрет-сабайди',
            'merchant_id' => 2573,
            'merchant_name' => 'Магазин',
            'affiliate_id' => 100500,
            'state' => 0,
            'type' => 0,
            'partner_payment' => '10.00',
            'order_payment' => '100.00',
            'currency' => 'rub',
            'items_in_order' => 1,
            'transition_at' => '2026-09-01T10:00:00+03:00',
            'created_at' => '2026-09-01T10:00:00+03:00',
            'last_updated_at' => '2026-09-01T10:00:00+03:00',
            'confirmed_at' => null,
            'accrued_at' => null,
            'keywords' => 'секретные слова',
        ], $override);
    }

    private static function map(mixed $payload): OrderList
    {
        return (new OrderMapper())->toList($payload, new OrderCriteria());
    }

    private static function order(string $id): Order
    {
        return self::map(Fixtures::json('orders/orders-synthetic.json'))->find($id) ?? throw new \LogicException('Нет заказа ' . $id);
    }
}
