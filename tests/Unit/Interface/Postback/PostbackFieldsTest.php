<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Postback;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Interface\Postback\PostbackFields;
use Webreboot\GdeSlon\Interface\Postback\PostbackMacro;

final class PostbackFieldsTest extends TestCase
{
    public function testMacrosAsInReference(): void
    {
        self::assertSame(
            [
                'gs_order_id', 'merchant_id', 'sub_id', 'sub_id2', 'sub_id3', 'sub_id4', 'sub_id5', 'profit', 'order_id',
                'order_sum', 'click_time', 'action_time', 'user_agent', 'state', 'price_in_currency', 'offer_name', 'currency',
                'click_id',
            ],
            array_map(static fn (PostbackMacro $macro): string => $macro->value, PostbackMacro::cases()),
        );
    }

    public function testDefaultNamesAreMacroNames(): void
    {
        $fields = new PostbackFields();

        foreach (PostbackMacro::cases() as $macro) {
            self::assertSame($macro->value, $fields->nameOf($macro));
        }
    }

    public function testOverrides(): void
    {
        $fields = new PostbackFields(['order_id' => 'someOrderId', '*profit*' => 'myProfit', ' click_id ' => ' clickId ']);

        self::assertSame('someOrderId', $fields->nameOf(PostbackMacro::OrderId));
        self::assertSame('myProfit', $fields->nameOf(PostbackMacro::Profit));
        self::assertSame('clickId', $fields->nameOf(PostbackMacro::ClickId));
        self::assertSame('state', $fields->nameOf(PostbackMacro::State));
    }

    public function testUnknownMacro(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('proft');
        $this->expectExceptionMessage('gs_order_id');

        new PostbackFields(['proft' => 'myProfit']);
    }

    public function testEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('profit');

        new PostbackFields(['profit' => ' ']);
    }

    public function testExplicitNameDisplacesDefault(): void
    {
        // в кабинете ID заказа «Где Слон?» назван order_id, а макрос *order_id* не передаётся
        $fields = new PostbackFields(['gs_order_id' => 'order_id']);

        self::assertSame('order_id', $fields->nameOf(PostbackMacro::GsOrderId));
        self::assertNull($fields->nameOf(PostbackMacro::OrderId), 'макрос не передаётся');
    }

    public function testTwoExplicitMacrosWithOneName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('«x»');

        new PostbackFields(['profit' => 'x', 'order_sum' => 'x']);
    }

    public function testRequiredMacroCannotBeDisplaced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('state');

        new PostbackFields(['profit' => 'state']);
    }
}
