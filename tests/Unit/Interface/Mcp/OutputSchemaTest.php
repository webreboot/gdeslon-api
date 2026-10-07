<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Mcp;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Mcp\Json;
use Webreboot\GdeSlon\Interface\Mcp\OutputSchema;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;

final class OutputSchemaTest extends TestCase
{
    public function testScalars(): void
    {
        self::assertSame('{"type":["integer","null"]}', Json::encode(OutputSchema::of('int|null')));
        self::assertSame('{"type":"string"}', Json::encode(OutputSchema::of('string')));
        self::assertSame('{"type":"boolean"}', Json::encode(OutputSchema::of('bool')));
    }

    public function testListsAndNullableObjects(): void
    {
        self::assertSame('{"type":"array","items":{"type":"string"}}', Json::encode(OutputSchema::of(['[]' => 'string'])));
        self::assertSame(
            '{"anyOf":[{"type":"object","properties":{"amount":{"type":"string"},"currency":{"type":"string"}},"required":["amount","currency"]},{"type":"null"}]}',
            Json::encode(OutputSchema::of(['?' => JsonShapes::MONEY])),
        );
    }

    public function testObjectRequiresAllKeysInOrder(): void
    {
        $schema = Json::toArray(self::object(OutputSchema::of(JsonShapes::CLAIM)));

        self::assertSame('object', $schema['type']);
        self::assertSame(array_keys(JsonShapes::CLAIM), $schema['required']);
        self::assertIsArray($schema['properties']);
        self::assertSame(array_keys(JsonShapes::CLAIM), array_keys($schema['properties']));
        self::assertArrayNotHasKey('additionalProperties', $schema, 'новые поля в минорной версии не ломают клиентов');
    }

    public function testEmptyObjectStaysObject(): void
    {
        self::assertSame('{"type":"object","properties":{},"required":[]}', Json::encode(OutputSchema::of([])));
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        OutputSchema::of('date');
    }

    private static function object(mixed $schema): \stdClass
    {
        $decoded = Json::decode(Json::encode($schema));
        self::assertInstanceOf(\stdClass::class, $decoded);

        return $decoded;
    }
}
