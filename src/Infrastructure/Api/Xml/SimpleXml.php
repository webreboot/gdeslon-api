<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Xml;

/**
 * Безопасное чтение SimpleXML без обращения через `->` (которое для отсутствующего элемента даёт null и TypeError).
 *
 * @internal
 */
final class SimpleXml
{
    private const BOOLEANS = ['true' => true, '1' => true, 'yes' => true, 'false' => false, '0' => false, 'no' => false];

    public static function child(?\SimpleXMLElement $element, string $name): ?\SimpleXMLElement
    {
        foreach ($element?->children() ?? [] as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    public static function children(?\SimpleXMLElement $element, string $name): array
    {
        $children = [];
        foreach ($element?->children() ?? [] as $child) {
            if ($child->getName() === $name) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Текст дочернего элемента (CDATA раскрыт) как есть; null — элемента нет или он из одних пробелов.
     */
    public static function text(\SimpleXMLElement $element, string $name): ?string
    {
        $child = self::child($element, $name);
        $text = $child === null ? '' : (string) $child;

        return trim($text) === '' ? null : $text;
    }

    /**
     * Текст дочернего элемента без пробелов по краям; null — элемента нет или он пуст.
     */
    public static function optional(\SimpleXMLElement $element, string $name): ?string
    {
        $text = self::text($element, $name);

        return $text === null ? null : trim($text);
    }

    public static function attribute(\SimpleXMLElement $element, string $name): ?string
    {
        $attributes = $element->attributes();

        return $attributes !== null && isset($attributes[$name]) ? (string) $attributes[$name] : null;
    }

    /**
     * true/1/yes и false/0/no (без учёта регистра и пробелов); иначе null.
     */
    public static function boolean(string $value): ?bool
    {
        return self::BOOLEANS[strtolower(trim($value))] ?? null;
    }

    /**
     * @param non-empty-string $separator
     *
     * @return list<string> непустые части без пробелов по краям
     */
    public static function split(?string $value, string $separator): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode($separator, $value)), static fn (string $item): bool => $item !== ''));
    }
}
