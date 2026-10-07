<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

/**
 * JSON протокола: одна строка без переводов строк (stdio-транспорт), `{}` и `[]` различаются.
 *
 * @internal
 */
final class Json
{
    private const MAX_DEPTH = 64;

    private function __construct()
    {
    }

    /**
     * Без pretty print; `\n`, `\r`, U+2028/2029 экранируются, битый UTF-8 заменяется на U+FFFD.
     *
     * @throws \JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Объекты — stdClass, чтобы отличать `{}` от `[]`.
     *
     * @throws \JsonException
     */
    public static function decode(string $text): mixed
    {
        return json_decode($text, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    }

    /**
     * Массив, который кодируется как объект даже пустым (`{}`).
     *
     * @param array<string, mixed> $properties
     */
    public static function object(array $properties): \stdClass
    {
        return (object) $properties;
    }

    /**
     * Объект из decode() → массив (вложенные объекты — тоже массивы).
     *
     * @return array<string, mixed>
     */
    public static function toArray(\stdClass $object): array
    {
        $array = [];
        foreach (get_object_vars($object) as $key => $value) {
            $array[(string) $key] = self::plain($value);
        }

        return $array;
    }

    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return self::toArray($value);
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }
}
