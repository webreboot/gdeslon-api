<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class CategoryTest extends TestCase
{
    public function testRootCategory(): void
    {
        $category = self::category(1, null, 'Подарки, сувениры, цветы', [1], 521843);

        self::assertSame(1, $category->id()->value());
        self::assertNull($category->parentId());
        self::assertSame('Подарки, сувениры, цветы', $category->name());
        self::assertFalse($category->isArchived());
        self::assertSame([1], self::ids($category->path()));
        self::assertSame(1, $category->depth());
        self::assertSame(521843, $category->offerCount());
        self::assertTrue($category->isRoot());
    }

    public function testNestedCategory(): void
    {
        $category = self::category(1231, 1230, 'Парки', [1113, 1216, 1217, 1230, 1231], null, true);

        self::assertSame(1230, $category->parentId()?->value());
        self::assertSame(5, $category->depth());
        self::assertTrue($category->isArchived());
        self::assertFalse($category->isRoot());
    }

    public function testNameIsTrimmedAndKeepsCyrillic(): void
    {
        self::assertSame('Станки', self::category(1026, 66, ' Станки ', [66, 1026])->name());
        self::assertSame('Всё для шитья', self::category(515, 495, 'Всё для шитья', [495, 515])->name());
    }

    #[DataProvider('blankNames')]
    public function testRejectsBlankName(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::category(1, null, $name, [1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankNames(): iterable
    {
        yield 'пустое' => [''];
        yield 'пробелы' => ['   '];
    }

    /**
     * @param list<int> $path
     */
    #[DataProvider('invalidPaths')]
    public function testRejectsInconsistentPath(int $id, ?int $parentId, array $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage((string) $id);

        self::category($id, $parentId, 'Категория', $path);
    }

    /**
     * @return iterable<string, array{int, ?int, list<int>}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'пустой путь' => [1, null, []];
        yield 'последний элемент не сама категория' => [2, 1, [1, 3]];
        yield 'у корня путь длиннее одного' => [1, null, [263, 1]];
        yield 'предпоследний элемент не родитель' => [3, 1, [2, 3]];
        yield 'у дочерней путь из одного элемента' => [5, 1, [5]];
        yield 'повтор в пути' => [3, 2, [1, 2, 1, 2, 3]];
    }

    public function testOfferCountMayBeUnknownOrZero(): void
    {
        self::assertNull(self::category(2, 1, 'Украшения', [1, 2])->offerCount());
        self::assertSame(0, self::category(2, 1, 'Украшения', [1, 2], 0)->offerCount());
    }

    public function testRejectsNegativeOfferCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::category(2, 1, 'Украшения', [1, 2], -1);
    }

    public function testOrphanWithMissingParentIsValid(): void
    {
        $orphan = self::category(1052, 277, 'Сушильные и гладильные машины', [263, 277, 1052]);

        self::assertSame(277, $orphan->parentId()?->value());
        self::assertSame([263, 277, 1052], self::ids($orphan->path()));
        self::assertSame(3, $orphan->depth());
    }

    /**
     * @param list<int> $path
     */
    private static function category(
        int $id,
        ?int $parentId,
        string $name,
        array $path,
        ?int $offerCount = null,
        bool $archived = false,
    ): Category {
        return new Category(
            new CategoryId($id),
            $parentId === null ? null : new CategoryId($parentId),
            $name,
            $archived,
            array_map(static fn (int $value): CategoryId => new CategoryId($value), $path),
            $offerCount,
        );
    }

    /**
     * @param list<CategoryId> $path
     *
     * @return list<int>
     */
    private static function ids(array $path): array
    {
        return array_map(static fn (CategoryId $id): int => $id->value(), $path);
    }
}
