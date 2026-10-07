<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\ContentRange;

final class ContentRangeTest extends TestCase
{
    /**
     * @param array{int, int, int, int} $expected
     */
    #[DataProvider('validRanges')]
    public function testParsesSingleByteRange(string $header, array $expected): void
    {
        $range = ContentRange::parse($header);

        self::assertNotNull($range);
        self::assertSame($expected, [$range->start(), $range->end(), $range->total(), $range->length()]);
    }

    /**
     * @return iterable<string, array{string, array{int, int, int, int}}>
     */
    public static function validRanges(): iterable
    {
        yield 'первая часть' => ['bytes 0-16383/159451', [0, 16383, 159451, 16384]];
        yield 'последняя часть' => ['bytes 147456-159450/159451', [147456, 159450, 159451, 11995]];
        yield 'один байт' => ['bytes 0-0/1', [0, 0, 1, 1]];
    }

    #[DataProvider('invalidRanges')]
    public function testRejectsUnusableValues(string $header): void
    {
        self::assertNull(ContentRange::parse($header));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'ответ 416' => ['bytes */159451'];
        yield 'размер неизвестен' => ['bytes 0-99/*'];
        yield 'конец раньше начала' => ['bytes 100-99/200'];
        yield 'конец за размером' => ['bytes 0-200/200'];
        yield 'чужие единицы' => ['items 0-1/2'];
        yield 'без размера' => ['bytes 0-99'];
        yield 'пусто' => [''];
        yield 'отрицательное начало' => ['bytes -1-5/10'];
        yield 'переполнение' => ['bytes 0-1/99999999999999999999'];
        yield 'multipart' => ['multipart/byteranges; boundary=00000000000000000001'];
    }
}
