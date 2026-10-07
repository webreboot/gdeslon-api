<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class CategoryIdTest extends TestCase
{
    public function testHoldsPositiveId(): void
    {
        $id = new CategoryId(1114);

        self::assertSame(1114, $id->value());
        self::assertSame('1114', (string) $id);
    }

    #[DataProvider('nonPositiveIds')]
    public function testRejectsNonPositiveId(int $value): void
    {
        try {
            new CategoryId($value);
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertInstanceOf(GdeSlonException::class, $e);
            self::assertStringContainsString((string) $value, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveIds(): iterable
    {
        yield 'ноль' => [0];
        yield 'отрицательный' => [-5];
    }

    public function testEqualsComparesValues(): void
    {
        self::assertTrue((new CategoryId(1))->equals(new CategoryId(1)));
        self::assertFalse((new CategoryId(1))->equals(new CategoryId(2)));
    }
}
