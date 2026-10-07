<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Sales;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;

final class OrderStateTest extends TestCase
{
    public function testStatesAsInApi(): void
    {
        self::assertSame(
            [OrderState::New, OrderState::Cancelled, OrderState::Pending, OrderState::Confirmed, OrderState::Paid],
            array_map(static fn (int $state): OrderState => OrderState::from($state), [0, 1, 2, 3, 4]),
        );
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn (OrderState $state): int => $state->value, OrderState::cases()), 'других статусов нет');
    }

    public function testTypesAndDateFieldsAsInApi(): void
    {
        self::assertSame(OrderType::Product, OrderType::from(0));
        self::assertSame(OrderType::Lead, OrderType::from(1));
        self::assertSame(
            ['transition_at', 'created_at', 'last_updated_at', 'confirmed_at', 'accrued_at'],
            array_map(static fn (OrderDateField $field): string => $field->value, OrderDateField::cases()),
        );
    }
}
