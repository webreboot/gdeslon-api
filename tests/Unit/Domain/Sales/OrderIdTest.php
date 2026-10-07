<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Sales;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Sales\OrderId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OrderIdTest extends TestCase
{
    public function testValueIsTrimmed(): void
    {
        $id = new OrderId(' 81234567 ');

        self::assertSame('81234567', $id->value());
        self::assertSame('81234567', (string) $id);
        self::assertTrue($id->equals(new OrderId('81234567')));
        self::assertFalse($id->equals(new OrderId('81234568')));
    }

    public function testEmptyIdIsRejected(): void
    {
        foreach (['', '  ', "\n"] as $value) {
            try {
                new OrderId($value);
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
