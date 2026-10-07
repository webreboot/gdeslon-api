<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Postback;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Postback\InvalidPostbackException;
use Webreboot\GdeSlon\Interface\Postback\QueryStringParser;

final class QueryStringParserTest extends TestCase
{
    public function testDecodesValues(): void
    {
        self::assertSame(
            ['a' => '1', 'b' => 'тест', 'c' => '', 'd' => 'x y', 'e' => 'x+y', 'f' => '=&'],
            QueryStringParser::parse('a=1&b=%D1%82%D0%B5%D1%81%D1%82&c=&d=x+y&e=x%2By&f=%3D%26'),
        );
    }

    public function testNamesAreNotMangled(): void
    {
        self::assertSame(
            ['sub.id' => '1', 'my profit' => '2', 'a[]' => '3', 'flag' => ''],
            QueryStringParser::parse('sub.id=1&my+profit=2&a%5B%5D=3&flag'),
        );
        self::assertSame([], QueryStringParser::parse(''));
        self::assertSame(['a' => '1'], QueryStringParser::parse('&a=1&&'));
    }

    public function testRepeatedNameIsRejected(): void
    {
        try {
            QueryStringParser::parse('merchant_id=1&merchant_id=2');
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(400, $e->responseStatus());
            self::assertSame('merchant_id', $e->field());
        }
    }

    public function testTooManyParameters(): void
    {
        $this->expectException(InvalidPostbackException::class);
        $this->expectExceptionMessage('200');

        QueryStringParser::parse(implode('&', array_map(static fn (int $i): string => 'p' . $i . '=1', range(1, 201))));
    }
}
