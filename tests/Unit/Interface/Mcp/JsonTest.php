<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Mcp;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Mcp\Json;

final class JsonTest extends TestCase
{
    public function testEncodeIsOneLine(): void
    {
        $json = Json::encode(['a' => 'Привет/x', 'n' => "a\nb\r", 'e' => Json::object([]), 'l' => [], 'ls' => "x\u{2028}y"]);

        self::assertSame('{"a":"Привет/x","n":"a\nb\r","e":{},"l":[],"ls":"x\u2028y"}', $json);
        self::assertStringNotContainsString("\n", $json);
        self::assertStringNotContainsString("\r", $json);
    }

    public function testEncodeReplacesInvalidUtf8(): void
    {
        self::assertSame('{"s":"a�b"}', Json::encode(['s' => "a\xffb"]));
    }

    public function testDecodeKeepsObjectsApartFromArrays(): void
    {
        $value = Json::decode('{"params":{},"list":[]}');

        self::assertInstanceOf(\stdClass::class, $value);
        self::assertInstanceOf(\stdClass::class, $value->params);
        self::assertSame([], $value->list);
    }

    public function testDecodeErrors(): void
    {
        foreach (['{', '', str_repeat('[', 65) . str_repeat(']', 65)] as $text) {
            try {
                Json::decode($text);
                self::fail('ожидалась ошибка: ' . substr($text, 0, 10));
            } catch (\JsonException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testToArray(): void
    {
        $value = Json::decode('{"a":{"b":[{"c":1}]},"d":"x"}');
        self::assertInstanceOf(\stdClass::class, $value);

        self::assertSame(['a' => ['b' => [['c' => 1]]], 'd' => 'x'], Json::toArray($value));
    }
}
