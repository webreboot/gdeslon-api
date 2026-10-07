<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Postback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Postback\InvalidPostbackException;
use Webreboot\GdeSlon\Interface\Postback\JsonBodyParser;
use Webreboot\GdeSlon\Interface\Postback\NonScalarValue;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class JsonBodyParserTest extends TestCase
{
    public function testFlatObject(): void
    {
        $fields = JsonBodyParser::parse('{"merchant_id":"2573","gs_order_id":900001,"profit":123.45,"big":123456789012345678901,"neg":-1.5,'
            . '"offer_name":"Магазин","sub_id":"тест","none":null,"flag":true,"list":[1],"obj":{"a":1}}');

        self::assertSame('2573', $fields['merchant_id']);
        self::assertSame('900001', $fields['gs_order_id']);
        self::assertSame('123.45', $fields['profit']);
        self::assertSame('123456789012345678901', $fields['big']);
        self::assertSame('-1.5', $fields['neg']);
        self::assertSame('Магазин', $fields['offer_name']);
        self::assertSame('тест', $fields['sub_id']);
        self::assertArrayNotHasKey('none', $fields, 'null — поля нет');
        self::assertInstanceOf(NonScalarValue::class, $fields['flag']);
        self::assertInstanceOf(NonScalarValue::class, $fields['list']);
        self::assertInstanceOf(NonScalarValue::class, $fields['obj']);
    }

    #[DataProvider('notObjects')]
    public function testNotObject(string $body, string $message): void
    {
        try {
            JsonBodyParser::parse($body);
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(400, $e->responseStatus());
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notObjects(): iterable
    {
        yield 'массив' => ['[]', 'не объект'];
        yield 'пустой объект как массив' => ['[{"a":1}]', 'не объект'];
        yield 'строка' => ['"x"', 'не объект'];
        yield 'null' => ['null', 'не объект'];
        yield 'пусто' => ['', 'пуст'];
        yield 'синтаксис' => ['{"a":1,}', 'в двойных кавычках'];
        yield 'пример из FAQ' => [Fixtures::read('postback/json-faq-example.txt'), 'в двойных кавычках'];
        yield 'не UTF-8' => ["{\"a\":\"\xff\"}", 'UTF-8'];
        yield 'глубина' => ['{"a":' . str_repeat('[', 9) . str_repeat(']', 9) . '}', 'вложен'];
    }

    public function testEmptyObjectIsObject(): void
    {
        self::assertSame([], JsonBodyParser::parse(' {} '));
    }

    public function testDuplicateTopLevelKeyIsRejected(): void
    {
        try {
            JsonBodyParser::parse('{"merchant_id":"1","state":"3","st\u0061te":"4"}');
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(400, $e->responseStatus());
            self::assertSame('state', $e->field());
        }

        $fields = JsonBodyParser::parse('{"a":{"b":1,"b":2},"c":"state","d":"state","e":"{\"x\":1,\"x\":2}"}');
        self::assertInstanceOf(NonScalarValue::class, $fields['a']);
        self::assertSame('state', $fields['d']);
    }
}
