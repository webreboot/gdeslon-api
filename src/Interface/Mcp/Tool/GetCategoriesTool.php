<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\Json;
use Webreboot\GdeSlon\Interface\Mcp\NotFoundException;
use Webreboot\GdeSlon\Interface\Mcp\Page;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;

/**
 * @internal
 */
final class GetCategoriesTool extends ReadTool
{
    public function name(): string
    {
        return 'get_categories';
    }

    public function title(): string
    {
        return 'Товарные категории';
    }

    public function description(): string
    {
        return 'Дерево товарных категорий «Где Слон?» (ключ не нужен). Без аргументов — корневые категории; category_id — '
            . 'категория, путь от корня (breadcrumbs) и подкатегории на depth уровней; name_contains — поиск по названию. '
            . 'ID категорий нужны для search_offers (category_ids, exclude_category_ids).';
    }

    public function inputProperties(): array
    {
        return [
            'category_id' => self::id('ID категории'),
            'depth' => Json::object(['type' => 'integer', 'minimum' => 0, 'maximum' => 5, 'description' => 'уровней подкатегорий; если не задан: без category_id — 0, с ним — 1']),
            'name_contains' => self::text('подстрока названия без учёта регистра (не вместе с category_id)', 100),
            ...Page::inputSchema(100, 500),
        ];
    }

    public function outputShape(): array
    {
        return ['categories' => ['[]' => JsonShapes::CATEGORY], 'breadcrumbs' => ['[]' => ['id' => 'int', 'name' => 'string']]] + self::PAGE_SHAPE;
    }

    public function credentials(): Credentials
    {
        return Credentials::None;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $id = $arguments->int('category_id', 1);
        $depth = $arguments->int('depth', 0, 5);
        $name = $arguments->string('name_contains', 100);
        $page = Page::of($arguments, 100, 500);
        if ($id !== null && $name !== null) {
            $arguments->reject('category_id и name_contains вместе не указываются');
        }
        $arguments->done();

        $tree = $context->gdeslon()->categories();
        $breadcrumbs = [];
        if ($name !== null) {
            $pattern = '/' . preg_quote($name, '/') . '/iu';
            $categories = array_values(array_filter($tree->all(), static fn (Category $c): bool => preg_match($pattern, $c->name()) === 1));
        } elseif ($id !== null) {
            if (!$tree->has($id)) {
                throw new NotFoundException(sprintf('Категории %d нет', $id));
            }
            $categories = self::subtree($tree, [$tree->get($id)], $depth ?? 1);
            $breadcrumbs = array_map(static fn (Category $c): array => ['id' => $c->id()->value(), 'name' => $c->name()], $tree->breadcrumbs($id));
        } else {
            $categories = self::subtree($tree, [...$tree->roots(), ...$tree->orphans()], $depth ?? 0);
        }

        return [
            'categories' => array_map(CatalogNormalizer::category(...), $page->slice($categories)),
            'breadcrumbs' => $breadcrumbs,
        ] + $page->meta(count($categories));
    }

    /**
     * Обход в глубину: категория, затем её дети до $depth уровней.
     *
     * @param list<Category> $start
     *
     * @return list<Category>
     */
    private static function subtree(CategoryTree $tree, array $start, int $depth, int $level = 0): array
    {
        $result = [];
        foreach ($start as $category) {
            $result[] = $category;
            if ($level < $depth) {
                $result = [...$result, ...self::subtree($tree, $tree->childrenOf($category->id()), $depth, $level + 1)];
            }
        }

        return $result;
    }
}
