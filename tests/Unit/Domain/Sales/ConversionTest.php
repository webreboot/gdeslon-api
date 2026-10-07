<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Sales;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Sales\Conversion;
use Webreboot\GdeSlon\Domain\Sales\OrderId;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class ConversionTest extends TestCase
{
    public function testMinimalConversion(): void
    {
        $conversion = new Conversion(merchantId: 2573, state: OrderState::Confirmed);

        self::assertSame(2573, $conversion->merchantId()->value());
        self::assertSame(OrderState::Confirmed, $conversion->state());
        self::assertNull($conversion->orderId());
        self::assertNull($conversion->merchantOrderNumber());
        self::assertSame([], $conversion->subIds());
        self::assertNull($conversion->subId());
        self::assertNull($conversion->reward());
        self::assertNull($conversion->orderSum());
        self::assertNull($conversion->priceInCurrency());
        self::assertNull($conversion->currency());
        self::assertNull($conversion->clickedAt());
        self::assertNull($conversion->actionAt());
        self::assertNull($conversion->userAgent());
        self::assertNull($conversion->offerName());
        self::assertNull($conversion->clickId());
        self::assertNull($conversion->deduplicationKey());
    }

    public function testFullConversion(): void
    {
        $clicked = new \DateTimeImmutable('2026-10-07T12:34:56+03:00');
        $action = new \DateTimeImmutable('2026-10-07T13:00:00+03:00');

        $conversion = new Conversion(
            merchantId: new MerchantId(2573),
            state: OrderState::Paid,
            orderId: new OrderId('900001'),
            merchantOrderNumber: "A-100/7#1\r\n",
            subIds: [1 => 'blog', 2 => 'тест 1+2', 5 => 'x'],
            reward: '123.45',
            orderSum: '1999.90',
            priceInCurrency: '25.5',
            currency: 'USD',
            clickedAt: $clicked,
            actionAt: $action,
            userAgent: 'Mozilla/5.0 (X11)',
            offerName: 'Магазин «Тест» & Co',
            clickId: 'abc123',
        );

        self::assertSame('900001', $conversion->orderId()?->value());
        self::assertSame('A-100/7#1', $conversion->merchantOrderNumber());
        self::assertSame([1 => 'blog', 2 => 'тест 1+2', 5 => 'x'], $conversion->subIds());
        self::assertSame('blog', $conversion->subId());
        self::assertSame('тест 1+2', $conversion->subId(2));
        self::assertNull($conversion->subId(3));
        self::assertSame('123.45', $conversion->reward());
        self::assertSame('1999.90', $conversion->orderSum());
        self::assertSame('25.5', $conversion->priceInCurrency());
        self::assertSame('USD', $conversion->currency());
        self::assertSame($clicked, $conversion->clickedAt());
        self::assertSame($action, $conversion->actionAt());
        self::assertSame('Mozilla/5.0 (X11)', $conversion->userAgent());
        self::assertSame('Магазин «Тест» & Co', $conversion->offerName());
        self::assertSame('abc123', $conversion->clickId());
        self::assertSame('900001', (new Conversion(1, OrderState::New, orderId: ' 900001 '))->orderId()?->value());
    }

    public function testTextsAndSubIdsAreNormalized(): void
    {
        $conversion = new Conversion(
            merchantId: 1,
            state: OrderState::New,
            merchantOrderNumber: '  ',
            subIds: [1 => ' a ', 3 => 'тест', 5 => ''],
            userAgent: "line1\r\nline2",
            offerName: '',
            clickId: ' ',
        );

        self::assertSame([1 => 'a', 3 => 'тест'], $conversion->subIds());
        self::assertNull($conversion->merchantOrderNumber());
        self::assertSame("line1\nline2", $conversion->userAgent());
        self::assertNull($conversion->offerName());
        self::assertNull($conversion->clickId());
    }

    public function testDeduplicationKey(): void
    {
        self::assertSame('gdeslon:900001:3', (new Conversion(2573, OrderState::Confirmed, orderId: '900001', merchantOrderNumber: 'A-100'))->deduplicationKey());
        self::assertSame('merchant:2573:A-100:3', (new Conversion(2573, OrderState::Confirmed, merchantOrderNumber: 'A-100'))->deduplicationKey());
        self::assertSame('gdeslon:900001:4', (new Conversion(2573, OrderState::Paid, orderId: '900001'))->deduplicationKey(), 'другой статус — другой ключ');
    }

    /**
     * @param \Closure(): mixed $create
     */
    #[DataProvider('invalidConversions')]
    public function testInvariants(\Closure $create, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidConversions(): iterable
    {
        yield 'магазин 0' => [static fn (): Conversion => new Conversion(0, OrderState::New), 'магазина'];
        yield 'пустой ID заказа' => [static fn (): Conversion => new Conversion(1, OrderState::New, orderId: '  '), 'ID заказа'];
        yield 'sub_id 0' => [static fn (): Conversion => new Conversion(1, OrderState::New, subIds: [0 => 'a']), 'sub_id'];
        yield 'sub_id 6' => [static fn (): Conversion => new Conversion(1, OrderState::New, subIds: [6 => 'a']), 'sub_id'];
        yield 'sub_id не UTF-8' => [static fn (): Conversion => new Conversion(1, OrderState::New, subIds: [1 => "\xff"]), 'UTF-8'];
        yield 'subId(0)' => [static fn (): ?string => (new Conversion(1, OrderState::New))->subId(0), 'sub_id'];
        yield 'subId(6)' => [static fn (): ?string => (new Conversion(1, OrderState::New))->subId(6), 'sub_id'];
        foreach (['-1', '1,5', '1e3', '', ' 1', "1\n"] as $amount) {
            yield 'сумма «' . $amount . '»' => [static fn (): Conversion => new Conversion(1, OrderState::New, reward: $amount), 'сумма'];
        }
        yield 'сумма заказа' => [static fn (): Conversion => new Conversion(1, OrderState::New, orderSum: 'x'), 'сумма'];
        yield 'сумма в валюте' => [static fn (): Conversion => new Conversion(1, OrderState::New, priceInCurrency: 'x'), 'сумма'];
        foreach (['rub', 'RU', 'РУБ', "RUB\n"] as $currency) {
            yield 'валюта «' . $currency . '»' => [static fn (): Conversion => new Conversion(1, OrderState::New, currency: $currency), 'валют'];
        }
    }
}
