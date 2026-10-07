<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Infrastructure\Json\JsonDecimal;

/**
 * Тело postback типа `json` → параметры. Ожидается плоский JSON-объект (шаблон тела вебмастер пишет сам в кабинете).
 * Числа превращаются в строки без шума float, null — «поля нет», массивы, объекты и true/false — NonScalarValue.
 *
 * @internal
 */
final class JsonBodyParser
{
    private const MAX_DEPTH = 8;

    /**
     * @return array<string, string|NonScalarValue>
     *
     * @throws InvalidPostbackException не JSON, не объект, глубже 8 уровней, не UTF-8
     */
    public static function parse(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new InvalidPostbackException('Postback: пустое тело JSON');
        }

        try {
            $decoded = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new InvalidPostbackException(self::reason($e), 400, null, $e);
        }
        // «{}» и «[]» после json_decode(…, true) оба — пустой массив: различаем по первому символу
        if (!is_array($decoded) || !str_starts_with($body, '{')) {
            throw new InvalidPostbackException(sprintf('Postback: тело JSON не объект, а %s', is_array($decoded) ? 'массив' : get_debug_type($decoded)));
        }

        self::assertUniqueKeys($body);

        $fields = [];
        foreach ($decoded as $name => $value) {
            $value = self::scalar($value);
            if ($value !== null) {
                $fields[(string) $name] = $value;
            }
        }

        return $fields;
    }

    /**
     * json_decode() молча оставляет последний из повторённых ключей — а query и XML на повтор отвечают 400. Ищем повтор
     * ключей верхнего уровня по уже проверенному JSON: строка после «{» или «,» на глубине 1 — ключ.
     */
    private static function assertUniqueKeys(string $json): void
    {
        $seen = [];
        $depth = 0;
        $expectKey = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($char === '"') {
                $end = $i + 1;
                while ($end < $length && $json[$end] !== '"') {
                    $end += $json[$end] === '\\' ? 2 : 1;
                }
                if ($depth === 1 && $expectKey) {
                    $key = json_decode(substr($json, $i, $end - $i + 1));
                    if (is_string($key)) {
                        if (isset($seen[$key])) {
                            throw InvalidPostbackException::forField($key, sprintf('Postback: ключ «%s» в JSON повторяется', PostbackText::safe($key)));
                        }
                        $seen[$key] = true;
                    }
                    $expectKey = false;
                }
                $i = $end;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
                $expectKey = $char === '{' && $depth === 1;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 1) {
                $expectKey = true;
            }
        }
    }

    private static function scalar(mixed $value): string|NonScalarValue|null
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => $value < 0
                ? '-' . (JsonDecimal::fromFloat(-$value) ?? (string) -$value)
                : (JsonDecimal::fromFloat($value) ?? (string) $value),
            default => new NonScalarValue(get_debug_type($value)),
        };
    }

    private static function reason(\JsonException $e): string
    {
        return match ($e->getCode()) {
            JSON_ERROR_DEPTH => sprintf('Postback: JSON вложен глубже %d уровней', self::MAX_DEPTH),
            JSON_ERROR_UTF8, JSON_ERROR_UTF16 => 'Postback: JSON не в UTF-8',
            default => sprintf(
                'Postback: тело не JSON (%s); в шаблоне тела ключи и макросы — в двойных кавычках: {"profit": "*profit*"}',
                $e->getMessage(),
            ),
        };
    }
}
