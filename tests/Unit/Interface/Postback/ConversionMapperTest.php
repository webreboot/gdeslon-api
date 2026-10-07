<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Postback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Sales\Conversion;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Interface\Postback\ConversionMapper;
use Webreboot\GdeSlon\Interface\Postback\InvalidPostbackException;
use Webreboot\GdeSlon\Interface\Postback\NonScalarValue;
use Webreboot\GdeSlon\Interface\Postback\PostbackFields;
use Webreboot\GdeSlon\Interface\Postback\QueryStringParser;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

/**
 * Значения — СИНТЕТИКА по документации (FAQ 24): формат времени и сумм в реальном postback не проверен.
 */
final class ConversionMapperTest extends TestCase
{
    private const FORMAT = 'Y-m-d\TH:i:sP';

    /** Значения, которые не должны попадать в предупреждения и сообщения. */
    private const PRIVATE_VALUES = ['секрет-сабайди', 'секретный-агент', 'секретный-оффер', 'секретный-номер'];

    public function testAllMacros(): void
    {
        [$conversion, $warnings] = self::map(QueryStringParser::parse(Fixtures::read('postback/get-all-macros.query')));

        self::assertSame([], $warnings);
        self::assertSame(2573, $conversion->merchantId()->value());
        self::assertSame(OrderState::Confirmed, $conversion->state());
        self::assertSame('900001', $conversion->orderId()?->value());
        self::assertSame('A-100/7#1', $conversion->merchantOrderNumber());
        self::assertSame([1 => 'тест 1+2 &x=y', 2 => '"кавычки" <b>'], $conversion->subIds());
        self::assertSame('123.45', $conversion->reward());
        self::assertSame('1999.90', $conversion->orderSum());
        self::assertSame('25.5', $conversion->priceInCurrency());
        self::assertSame('USD', $conversion->currency());
        self::assertSame('2026-10-07T12:34:56+03:00', $conversion->clickedAt()?->format(self::FORMAT));
        self::assertNull($conversion->actionAt());
        self::assertSame('Mozilla/5.0 (X11) "q" <x> \ end', $conversion->userAgent());
        self::assertSame('Магазин «Тест» & Co', $conversion->offerName());
        self::assertSame('abc123', $conversion->clickId());
    }

    public function testCustomNamesFromFaqScreenshot(): void
    {
        $fields = new PostbackFields(['order_id' => 'someOrderId', 'profit' => 'myProfit', 'click_id' => 'clickId']);

        [$conversion, $warnings] = self::map(QueryStringParser::parse(Fixtures::read('postback/faq-screenshot.query')), $fields);

        self::assertSame('1234567', $conversion->merchantOrderNumber());
        self::assertNull($conversion->reward(), '«eewrwer» — не сумма');
        self::assertNull($conversion->clickId(), 'пустой параметр — нет значения');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('profit', $warnings[0]);
        self::assertStringContainsString('myProfit', $warnings[0]);
    }

    #[DataProvider('invalidMerchantIds')]
    public function testInvalidMerchantId(?string $value): void
    {
        $parameters = ['state' => '3'] + ($value === null ? [] : ['merchant_id' => $value]);

        try {
            self::map($parameters);
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(400, $e->responseStatus());
            self::assertSame('merchant_id', $e->field());
            self::assertStringContainsString('merchant_id', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidMerchantIds(): iterable
    {
        foreach ([null, '', 'abc', '0', '-5', '12.0', '1234567890123456789'] as $value) {
            yield var_export($value, true) => [$value];
        }
    }

    public function testStates(): void
    {
        $expected = ['0' => OrderState::New, '1' => OrderState::Cancelled, '2' => OrderState::Pending, ' 3 ' => OrderState::Confirmed, '4' => OrderState::Paid,
            'created' => OrderState::New, 'Cancelled' => OrderState::Cancelled, 'pending' => OrderState::Pending, 'confirmed' => OrderState::Confirmed, 'PAYED' => OrderState::Paid];
        foreach ($expected as $value => $state) {
            self::assertSame($state, self::map(['merchant_id' => '1', 'state' => (string) $value])[0]->state(), (string) $value);
        }
    }

    #[DataProvider('invalidStates')]
    public function testInvalidState(?string $value): void
    {
        $this->expectException(InvalidPostbackException::class);
        $this->expectExceptionMessage('state');

        self::map(['merchant_id' => '1'] + ($value === null ? [] : ['state' => $value]));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidStates(): iterable
    {
        foreach ([null, '5', '-1', '', '3.0', 'abc', 'paid'] as $value) {
            yield var_export($value, true) => [$value];
        }
    }

    public function testOrderIdTrimmedOrEmpty(): void
    {
        self::assertSame('900001', self::map(['merchant_id' => '1', 'state' => '3', 'gs_order_id' => ' 900001 '])[0]->orderId()?->value());
        self::assertNull(self::map(['merchant_id' => '1', 'state' => '3', 'gs_order_id' => ''])[0]->orderId());
    }

    #[DataProvider('brokenOptionalFields')]
    public function testBrokenOptionalFieldBecomesNullWithWarning(string $macro, string|NonScalarValue $value, \Closure $get): void
    {
        [$conversion, $warnings] = self::map(['merchant_id' => '2573', 'state' => '3', $macro => $value]);

        self::assertNull($get($conversion));
        self::assertCount(1, $warnings);
        self::assertStringContainsString($macro, $warnings[0]);
        foreach (self::PRIVATE_VALUES as $private) {
            self::assertStringNotContainsString($private, $warnings[0]);
        }
    }

    /**
     * @return iterable<string, array{string, string|NonScalarValue, \Closure(Conversion): mixed}>
     */
    public static function brokenOptionalFields(): iterable
    {
        $reward = static fn (Conversion $c): ?string => $c->reward();
        foreach (['123,45', '1 999.90', '-10', 'eewrwer', '1e3'] as $amount) {
            yield 'profit «' . $amount . '»' => ['profit', $amount, $reward];
        }
        yield 'order_sum' => ['order_sum', 'x', static fn (Conversion $c): ?string => $c->orderSum()];
        yield 'price_in_currency' => ['price_in_currency', '1,5', static fn (Conversion $c): ?string => $c->priceInCurrency()];
        yield 'валюта кириллицей' => ['currency', 'руб', static fn (Conversion $c): ?string => $c->currency()];
        yield 'валюта из двух букв' => ['currency', 'RU', static fn (Conversion $c): ?string => $c->currency()];
        $clicked = static fn (Conversion $c): ?\DateTimeImmutable => $c->clickedAt();
        foreach (['2026-02-31 10:00:00', '07.10.2026', 'eewrwer'] as $time) {
            yield 'click_time «' . $time . '»' => ['click_time', $time, $clicked];
        }
        yield 'action_time' => ['action_time', 'вчера', static fn (Conversion $c): ?\DateTimeImmutable => $c->actionAt()];
        yield 'sub_id не UTF-8' => ['sub_id', "секрет-сабайди\xff", static fn (Conversion $c): ?string => $c->subId()];
        yield 'sub_id5 массивом' => ['sub_id5', new NonScalarValue('array'), static fn (Conversion $c): ?string => $c->subId(5)];
        yield 'user_agent не UTF-8' => ['user_agent', "секретный-агент\xff", static fn (Conversion $c): ?string => $c->userAgent()];
        yield 'offer_name объектом' => ['offer_name', new NonScalarValue('array'), static fn (Conversion $c): ?string => $c->offerName()];
        yield 'order_id не UTF-8' => ['order_id', "секретный-номер\xff", static fn (Conversion $c): ?string => $c->merchantOrderNumber()];
        yield 'gs_order_id не UTF-8' => ['gs_order_id', "\xff", static fn (Conversion $c): ?string => $c->orderId()?->value()];
    }

    public function testAmountsAndCurrency(): void
    {
        [$conversion, $warnings] = self::map(['merchant_id' => '1', 'state' => '3', 'profit' => ' 0 ', 'order_sum' => '1999.90', 'currency' => 'rub']);

        self::assertSame([], $warnings);
        self::assertSame('0', $conversion->reward());
        self::assertSame('1999.90', $conversion->orderSum(), 'как есть, без нормализации');
        self::assertSame('RUB', $conversion->currency());
        self::assertSame('RUR', self::map(['merchant_id' => '1', 'state' => '3', 'currency' => 'RUR'])[0]->currency());
    }

    public function testTimes(): void
    {
        $cases = [
            '2026-10-07 12:34:56' => '2026-10-07T12:34:56+03:00',
            '2026-10-07T12:34:56Z' => '2026-10-07T12:34:56+00:00',
            '2026-10-07T12:34:56+00:00' => '2026-10-07T12:34:56+00:00',
            '2026-10-07' => '2026-10-07T00:00:00+03:00',
            '1759829696' => '2025-10-07T09:34:56+00:00',
        ];
        foreach ($cases as $value => $expected) {
            [$conversion, $warnings] = self::map(['merchant_id' => '1', 'state' => '3', 'click_time' => (string) $value]);
            self::assertSame([], $warnings, (string) $value);
            self::assertSame($expected, $conversion->clickedAt()?->format(self::FORMAT), (string) $value);
        }
    }

    public function testMissingAndEmptyParametersAreNullWithoutWarnings(): void
    {
        [$conversion, $warnings] = self::map(['merchant_id' => '1', 'state' => '3', 'profit' => '', 'click_time' => '', 'sub_id' => '', 'unknown' => 'x']);

        self::assertSame([], $warnings);
        self::assertNull($conversion->reward());
        self::assertNull($conversion->clickedAt());
        self::assertSame([], $conversion->subIds());
    }

    public function testNonScalarRequiredFieldIsRejected(): void
    {
        $this->expectException(InvalidPostbackException::class);
        $this->expectExceptionMessage('merchant_id');

        self::map(['merchant_id' => new NonScalarValue('array'), 'state' => '3']);
    }

    public function testRejectedValueIsSanitizedInMessage(): void
    {
        try {
            self::map(['merchant_id' => "abc\r\nX-Injected: 1" . str_repeat('x', 100), 'state' => '3']);
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertStringNotContainsString("\n", $e->getMessage());
            self::assertStringNotContainsString("\r", $e->getMessage());
            self::assertStringNotContainsString(str_repeat('x', 41), $e->getMessage());
        }
    }

    /**
     * @param array<string, string|NonScalarValue> $parameters
     *
     * @return array{Conversion, list<string>}
     */
    private static function map(array $parameters, PostbackFields $fields = new PostbackFields()): array
    {
        return (new ConversionMapper($fields))->map($parameters);
    }
}
