<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;

/**
 * Ответ gdeslon-categories.json → дерево категорий.
 *
 * Форма ответа: объект {"<id>": {"_id", "parent_id", "name", "is_archived", "path", "offer_count"?}}
 * (docs/gdeslon-api/categories.md). Ключи объекта не используются — ID берётся из `_id`.
 * Любая битая запись — ошибка всего ответа.
 *
 * @internal
 */
final class CategoryMapper
{
    public function toTree(mixed $payload): CategoryTree
    {
        if (!is_array($payload)) {
            throw new UnexpectedResponseException(sprintf(
                'Ответ категорий: ожидался JSON-объект, получено %s',
                get_debug_type($payload),
            ));
        }

        $categories = [];
        foreach ($payload as $key => $record) {
            $categories[] = $this->toCategory((string) $key, $record);
        }

        try {
            return new CategoryTree($categories);
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedResponseException('Ответ категорий: ' . $e->getMessage(), $e);
        }
    }

    private function toCategory(string $key, mixed $record): Category
    {
        if (!is_array($record)) {
            throw $this->invalid($key, sprintf('запись должна быть объектом, получено %s', get_debug_type($record)));
        }

        $id = $this->requiredId($key, $record, '_id');
        $parentId = $this->optionalId($key, $record, 'parent_id');

        try {
            return new Category(
                $id,
                $parentId,
                $this->name($key, $record),
                $this->archived($key, $record),
                $this->path($key, $record),
                $this->offerCount($key, $record),
            );
        } catch (InvalidArgumentException $e) {
            throw $this->invalid($key, $e->getMessage(), $e);
        }
    }

    /**
     * @param array<mixed> $record
     */
    private function requiredId(string $key, array $record, string $field): CategoryId
    {
        if (!array_key_exists($field, $record)) {
            throw $this->invalid($key, sprintf('нет поля %s', $field));
        }

        return $this->id($key, $field, $record[$field]);
    }

    /**
     * @param array<mixed> $record
     */
    private function optionalId(string $key, array $record, string $field): ?CategoryId
    {
        $value = $record[$field] ?? null;

        return $value === null ? null : $this->id($key, $field, $value);
    }

    private function id(string $key, string $field, mixed $value): CategoryId
    {
        $int = self::positiveInt($value);
        if ($int === null) {
            throw $this->invalid($key, sprintf('поле %s должно быть положительным целым, получено %s', $field, self::describe($value)));
        }

        return new CategoryId($int);
    }

    /**
     * @param array<mixed> $record
     */
    private function name(string $key, array $record): string
    {
        $name = $record['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw $this->invalid($key, sprintf('поле name должно быть непустой строкой, получено %s', self::describe($name)));
        }

        return $name;
    }

    /**
     * @param array<mixed> $record
     */
    private function archived(string $key, array $record): bool
    {
        if (!array_key_exists('is_archived', $record)) {
            return false;
        }

        return match ($record['is_archived']) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => throw $this->invalid($key, sprintf(
                'поле is_archived должно быть логическим, получено %s',
                self::describe($record['is_archived']),
            )),
        };
    }

    /**
     * @param array<mixed> $record
     *
     * @return list<CategoryId>
     */
    private function path(string $key, array $record): array
    {
        $path = $record['path'] ?? null;
        if (!is_array($path) || $path === [] || !array_is_list($path)) {
            throw $this->invalid($key, sprintf('поле path должно быть непустым списком ID, получено %s', self::describe($path)));
        }

        return array_map(fn (mixed $value): CategoryId => $this->id($key, 'path', $value), $path);
    }

    /**
     * @param array<mixed> $record
     */
    private function offerCount(string $key, array $record): ?int
    {
        $value = $record['offer_count'] ?? null;
        if ($value === null) {
            return null;
        }

        $int = self::nonNegativeInt($value);
        if ($int === null) {
            throw $this->invalid($key, sprintf('поле offer_count должно быть неотрицательным целым, получено %s', self::describe($value)));
        }

        return $int;
    }

    private function invalid(string $key, string $reason, ?\Throwable $previous = null): UnexpectedResponseException
    {
        return new UnexpectedResponseException(sprintf('Ответ категорий: запись «%s»: %s', $key, $reason), $previous);
    }

    private static function positiveInt(mixed $value): ?int
    {
        $int = self::nonNegativeInt($value);

        return $int === 0 ? null : $int;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => sprintf('"%s"', $value),
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => get_debug_type($value),
        };
    }
}
