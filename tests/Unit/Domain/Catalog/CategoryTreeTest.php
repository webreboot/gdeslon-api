<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\CategoryNotFoundException;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class CategoryTreeTest extends TestCase
{
    public function testEmptyTree(): void
    {
        $tree = new CategoryTree([]);

        self::assertCount(0, $tree);
        self::assertSame([], $tree->all());
        self::assertSame([], $tree->roots());
        self::assertSame([], $tree->orphans());
        self::assertNull($tree->find(1));
        self::assertFalse($tree->has(1));
    }

    public function testGetByIntOrCategoryId(): void
    {
        $tree = self::tree();

        self::assertSame('Парки', $tree->get(1231)->name());
        self::assertSame($tree->get(1231), $tree->get(new CategoryId(1231)));
        self::assertSame($tree->get(1231), $tree->find(1231));
        self::assertTrue($tree->has(new CategoryId(1231)));
    }

    public function testGetUnknownCategoryThrows(): void
    {
        try {
            self::tree()->get(277);
            self::fail('Ожидалось исключение');
        } catch (CategoryNotFoundException $e) {
            self::assertInstanceOf(GdeSlonException::class, $e);
            self::assertStringContainsString('277', $e->getMessage());
            self::assertSame(277, $e->categoryId());
        }
    }

    /**
     * @param \Closure(CategoryTree): mixed $call
     */
    #[DataProvider('impossibleIds')]
    public function testImpossibleIdIsNotFoundToo(\Closure $call, int $id): void
    {
        try {
            $call(self::tree());
            self::fail('Ожидалось исключение');
        } catch (CategoryNotFoundException $e) {
            self::assertSame($id, $e->categoryId());
            self::assertStringContainsString((string) $id, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{\Closure(CategoryTree): mixed, int}>
     */
    public static function impossibleIds(): iterable
    {
        yield 'get(0)' => [static fn (CategoryTree $tree): Category => $tree->get(0), 0];
        yield 'get(-1)' => [static fn (CategoryTree $tree): Category => $tree->get(-1), -1];
        yield 'breadcrumbs(0)' => [static fn (CategoryTree $tree): array => $tree->breadcrumbs(0), 0];
    }

    public function testImpossibleIdsAreAbsent(): void
    {
        $tree = self::tree();

        self::assertNull($tree->find(0));
        self::assertFalse($tree->has(-1));
        self::assertSame([], $tree->childrenOf(0));
    }

    public function testRejectsDuplicateIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('121');

        new CategoryTree([
            self::category(121, null, 'Книги', [121]),
            self::category(121, null, 'Книги ещё раз', [121]),
        ]);
    }

    public function testRootsInInputOrderWithoutOrphans(): void
    {
        self::assertSame([1113, 263], self::idsOf(self::tree()->roots()));
    }

    public function testChildrenOf(): void
    {
        $tree = self::tree();

        self::assertSame([1216], self::idsOf($tree->childrenOf(1113)));
        self::assertSame([1231, 1232], self::idsOf($tree->childrenOf(new CategoryId(1230))));
        self::assertSame([], $tree->childrenOf(1231));
        self::assertSame([1052, 1054], self::idsOf($tree->childrenOf(277)), 'сироты отсутствующего родителя');
        self::assertSame([], $tree->childrenOf(99999));
    }

    public function testBreadcrumbsFromRoot(): void
    {
        $tree = self::tree();

        self::assertSame([1113, 1216, 1217, 1230, 1231], self::idsOf($tree->breadcrumbs(1231)));
        self::assertSame([1113], self::idsOf($tree->breadcrumbs(1113)));
    }

    public function testBreadcrumbsSkipMissingCategories(): void
    {
        $tree = self::tree();

        self::assertSame([263, 1052], self::idsOf($tree->breadcrumbs(1052)));
        self::assertSame([1992], self::idsOf($tree->breadcrumbs(1992)));
    }

    public function testBreadcrumbsOfUnknownCategoryThrows(): void
    {
        $this->expectException(CategoryNotFoundException::class);

        self::tree()->breadcrumbs(277);
    }

    public function testOrphans(): void
    {
        self::assertSame([1052, 1054, 1992], self::idsOf(self::tree()->orphans()));

        $withoutOrphans = new CategoryTree([
            self::category(1113, null, 'Одежда', [1113]),
            self::category(1216, 1113, 'Детская одежда', [1113, 1216]),
        ]);
        self::assertSame([], $withoutOrphans->orphans());
    }

    public function testIterationAndCountFollowInputOrder(): void
    {
        $tree = self::tree();

        self::assertCount(10, $tree);
        self::assertSame($tree->all(), iterator_to_array($tree, false));
        self::assertSame([1113, 1216, 1217, 1230, 1231, 1232, 263, 1052, 1054, 1992], self::idsOf($tree->all()));
    }

    private static function tree(): CategoryTree
    {
        return new CategoryTree([
            self::category(1113, null, 'Одежда', [1113]),
            self::category(1216, 1113, 'Детская одежда', [1113, 1216]),
            self::category(1217, 1216, 'Для девочек', [1113, 1216, 1217]),
            self::category(1230, 1217, 'Верхняя одежда', [1113, 1216, 1217, 1230]),
            self::category(1231, 1230, 'Парки', [1113, 1216, 1217, 1230, 1231]),
            self::category(1232, 1230, 'Плащи', [1113, 1216, 1217, 1230, 1232]),
            self::category(263, null, 'Спортивные товары', [263]),
            self::category(1052, 277, 'Сушильные и гладильные машины', [263, 277, 1052]),
            self::category(1054, 277, 'Стиральные машины', [263, 277, 1054]),
            self::category(1992, 50, 'Прочее (Все для дома и дачи)', [50, 1992]),
        ]);
    }

    /**
     * @param list<int> $path
     */
    private static function category(int $id, ?int $parentId, string $name, array $path): Category
    {
        return new Category(
            new CategoryId($id),
            $parentId === null ? null : new CategoryId($parentId),
            $name,
            false,
            array_map(static fn (int $value): CategoryId => new CategoryId($value), $path),
            null,
        );
    }

    /**
     * @param list<Category> $categories
     *
     * @return list<int>
     */
    private static function idsOf(array $categories): array
    {
        return array_map(static fn (Category $category): int => $category->id()->value(), $categories);
    }
}
