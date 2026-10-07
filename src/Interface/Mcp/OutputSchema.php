<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;

/**
 * JSON Schema (2020-12) для outputSchema инструментов из форм JsonShapes — тех же, по которым тест проверяет вывод
 * нормализаторов. additionalProperties не задаётся: новые поля в минорной версии не ломают клиентов.
 *
 * @internal
 */
final class OutputSchema
{
    private const TYPES = ['int' => 'integer', 'string' => 'string', 'bool' => 'boolean', 'null' => 'null'];

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed>|string $shape форма в нотации JsonShapes
     */
    public static function of(array|string $shape): \stdClass
    {
        if (is_string($shape)) {
            $types = array_map(self::type(...), explode('|', $shape));

            return Json::object(['type' => count($types) === 1 ? $types[0] : $types]);
        }
        if (array_key_exists('?', $shape)) {
            return Json::object(['anyOf' => [self::of(self::shape($shape['?'])), Json::object(['type' => 'null'])]]);
        }
        if (array_key_exists('[]', $shape)) {
            return Json::object(['type' => 'array', 'items' => self::of(self::shape($shape['[]']))]);
        }
        $properties = [];
        foreach ($shape as $key => $property) {
            $properties[(string) $key] = self::of(self::shape($property));
        }

        return Json::object(['type' => 'object', 'properties' => Json::object($properties), 'required' => array_keys($properties)]);
    }

    private static function type(string $type): string
    {
        return self::TYPES[$type] ?? throw new \LogicException(sprintf('Неизвестный тип формы JSON «%s»', $type));
    }

    /**
     * @return array<array-key, mixed>|string
     */
    private static function shape(mixed $shape): array|string
    {
        if (!is_string($shape) && !is_array($shape)) {
            throw new \LogicException('Форма JSON — строка типов или массив, см. ' . JsonShapes::class);
        }

        return $shape;
    }
}
