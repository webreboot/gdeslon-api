<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Claims;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class ClaimValuesTest extends TestCase
{
    public function testClaimId(): void
    {
        $id = new LostOrderClaimId(5796);

        self::assertSame(5796, $id->value());
        self::assertSame('5796', (string) $id);
        self::assertTrue($id->equals(new LostOrderClaimId(5796)));
        self::assertFalse($id->equals(new LostOrderClaimId(1)));

        foreach ([0, -1] as $invalid) {
            try {
                new LostOrderClaimId($invalid);
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testStatusesFromApi(): void
    {
        // в документации и статус ответа «in_waiting», и значение фильтра «waiting»
        self::assertSame(LostOrderStatus::Waiting, LostOrderStatus::fromApi('in_waiting'));
        self::assertSame(LostOrderStatus::Waiting, LostOrderStatus::fromApi('waiting'));
        self::assertSame(LostOrderStatus::Confirmed, LostOrderStatus::fromApi(' Confirmed '));
        self::assertSame(LostOrderStatus::Declined, LostOrderStatus::fromApi('declined'));
        foreach (['processing', '', '0'] as $unknown) {
            self::assertNull(LostOrderStatus::fromApi($unknown));
        }

        self::assertSame(LostOrderClaimState::InWork, LostOrderClaimState::fromApi('in_work'));
        self::assertSame(LostOrderClaimState::Closed, LostOrderClaimState::fromApi('CLOSED'));
        self::assertNull(LostOrderClaimState::fromApi('open'));
        self::assertNull(LostOrderClaimState::fromApi(''));
    }

    public function testOrderTotal(): void
    {
        $cases = ['554.34' => '554.34', '100' => '100.00', ' 7.5 ' => '7.50', '0' => '0.00', '0012.30' => '12.30'];
        foreach ($cases as $input => $expected) {
            self::assertSame($expected, OrderTotal::of((string) $input)->amount(), (string) $input);
        }
        self::assertSame('100.00', OrderTotal::of(100)->amount());
        self::assertSame('554.34', OrderTotal::of(554.34)->amount());
        self::assertSame('0.00', OrderTotal::of(0)->amount());
        self::assertSame('9999999999999.99', OrderTotal::of('9999999999999.99')->amount());
        self::assertTrue(OrderTotal::of('100')->equals(OrderTotal::of(100.0)));
        self::assertSame('554.34', (string) OrderTotal::of('554.34'));
    }

    #[DataProvider('invalidTotals')]
    public function testInvalidOrderTotal(string|int|float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderTotal::of($value);
    }

    public function testNonFiniteOrderTotal(): void
    {
        foreach ([NAN, INF, -INF] as $value) {
            try {
                OrderTotal::of($value);
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @return iterable<string, array{string|int|float}>
     */
    public static function invalidTotals(): iterable
    {
        yield 'шум float' => [0.1 + 0.2];
        yield 'три знака' => ['1.234'];
        yield 'отрицательная' => ['-1'];
        yield 'отрицательная int' => [-1];
        yield 'экспонента' => ['1e3'];
        yield 'запятая' => ['1,5'];
        yield 'пусто' => [''];
        yield 'пробел' => [' '];
        yield '14 цифр' => ['12345678901234'];
        yield 'точка без дробной части' => ['1.'];
    }
}
