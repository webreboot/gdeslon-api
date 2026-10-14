<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Yml;

use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\SimpleXml;

/** @internal */
final class FeedCategoriesMapper
{
    public function map(\SimpleXMLElement $categories): CategoryTree
    {
        $parents = [];
        $names = [];
        foreach (SimpleXml::children($categories, 'category') as $category) {
            $id = self::id(SimpleXml::attribute($category, 'id'), 'id');
            if (isset($names[$id])) {
                throw self::invalid(sprintf('категория %d встречается дважды', $id));
            }
            $parentId = SimpleXml::attribute($category, 'parent_id');
            $parents[$id] = $parentId === null || trim($parentId) === '' ? null : self::id($parentId, 'parent_id');
            $names[$id] = SimpleXml::decodedValue($category) ?? throw self::invalid(sprintf('пустое название категории %d', $id));
        }

        try {
            $tree = [];
            foreach ($names as $id => $name) {
                $parentId = $parents[$id];
                $tree[] = new Category(
                    new CategoryId($id),
                    $parentId === null ? null : new CategoryId($parentId),
                    $name,
                    false,
                    self::path($id, $parents),
                    null,
                );
            }

            return new CategoryTree($tree);
        } catch (InvalidArgumentException $e) {
            throw self::invalid($e->getMessage(), $e);
        }
    }

    /**
     * @param array<int, int|null> $parents
     *
     * @return list<CategoryId>
     */
    private static function path(int $id, array $parents): array
    {
        $path = [];
        for ($current = $id; $current !== null; $current = $parents[$current] ?? null) {
            if (isset($path[$current])) {
                throw self::invalid(sprintf('цикл в parent_id у категории %d', $id));
            }
            $path[$current] = new CategoryId($current);
            if (!array_key_exists($current, $parents)) {
                break;
            }
        }

        return array_reverse(array_values($path));
    }

    private static function id(?string $value, string $field): int
    {
        $value = trim($value ?? '');
        if (preg_match('/^\d{1,18}$/', $value) !== 1 || (int) $value === 0) {
            throw self::invalid(sprintf('%s категории должен быть положительным целым, получено «%s»', $field, $value));
        }

        return (int) $value;
    }

    private static function invalid(string $reason, ?\Throwable $previous = null): UnexpectedResponseException
    {
        return new UnexpectedResponseException('Категории выгрузки: ' . $reason, $previous);
    }
}
