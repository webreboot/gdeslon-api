<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\CategoryMapper;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class CategoryMapperTest extends TestCase
{
    private const VALID = ['_id' => 7, 'parent_id' => null, 'name' => 'Категория', 'is_archived' => false, 'path' => [7]];

    public function testMapsRealFixture(): void
    {
        $tree = self::fixtureTree();

        self::assertCount(23, $tree);

        $gifts = $tree->get(1);
        self::assertSame('Подарки, сувениры, цветы', $gifts->name());
        self::assertSame(521843, $gifts->offerCount());
        self::assertTrue($gifts->isRoot());
        self::assertFalse($gifts->isArchived());
        self::assertSame([1], self::ids($gifts->path()));

        $jewelry = $tree->get(2);
        self::assertNull($jewelry->offerCount());
        self::assertSame(1, $jewelry->parentId()?->value());

        self::assertSame(1150082, $tree->get(1114)->offerCount());
    }

    public function testRealFixtureStructure(): void
    {
        $tree = self::fixtureTree();

        self::assertSame([1, 66, 121, 263, 495, 1113, 2003], self::idsOf($tree->roots()));
        self::assertSame([1052, 1992], self::idsOf($tree->orphans()));
        self::assertSame(
            ['Одежда', 'Детская одежда', 'Для девочек', 'Верхняя одежда', 'Парки'],
            array_map(static fn (Category $category): string => $category->name(), $tree->breadcrumbs(1231)),
        );
    }

    public function testRealFixtureNames(): void
    {
        $tree = self::fixtureTree();

        self::assertSame('Станки', $tree->get(1026)->name());
        self::assertSame('Нижнее белье', $tree->get(1128)->name());
        self::assertSame('Нижнее белье', $tree->get(1522)->name());
        self::assertSame('Книги', $tree->get(121)->name());
        self::assertSame('Книги', $tree->get(655)->name());
        self::assertSame('Всё для шитья', $tree->get(515)->name());
    }

    public function testEmptyPayloadGivesEmptyTree(): void
    {
        self::assertCount(0, (new CategoryMapper())->toTree([]));
    }

    public function testKeysAreIgnoredIdComesFromRecord(): void
    {
        $tree = (new CategoryMapper())->toTree([
            self::VALID,
            '999' => ['_id' => 8, 'parent_id' => 7, 'name' => 'Дочерняя', 'is_archived' => false, 'path' => [7, 8]],
        ]);

        self::assertSame([7, 8], self::idsOf($tree->all()));
        self::assertFalse($tree->has(999));
    }

    #[DataProvider('invalidTopLevel')]
    public function testRejectsNonObjectTopLevel(mixed $payload): void
    {
        $this->expectException(UnexpectedResponseException::class);

        (new CategoryMapper())->toTree($payload);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTopLevel(): iterable
    {
        yield 'null' => [null];
        yield 'строка' => ['str'];
        yield 'число' => [5];
        yield 'bool' => [true];
    }

    #[DataProvider('nonObjectRecords')]
    public function testRejectsNonObjectRecord(mixed $record): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('«1»');

        (new CategoryMapper())->toTree(['1' => $record]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonObjectRecords(): iterable
    {
        yield 'число' => [5];
        yield 'null' => [null];
        yield 'строка' => ['x'];
    }

    public function testNumericStringsAreAccepted(): void
    {
        $category = self::mapOne([
            '_id' => '12',
            'parent_id' => '1',
            'name' => 'Строки',
            'is_archived' => '0',
            'path' => ['1', '12'],
            'offer_count' => '1500',
        ]);

        self::assertSame(12, $category->id()->value());
        self::assertSame(1, $category->parentId()?->value());
        self::assertSame([1, 12], self::ids($category->path()));
        self::assertSame(1500, $category->offerCount());
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidFields')]
    public function testRejectsInvalidField(array $override, string $field): void
    {
        try {
            self::mapOne(array_merge(self::VALID, $override));
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString($field, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidFields(): iterable
    {
        foreach (['ноль' => 0, 'строка ноль' => '0', 'отрицательный' => -1, 'дробный' => 1.5, 'с буквами' => '12a', 'пустая строка' => '', 'bool' => true, 'null' => null] as $case => $value) {
            yield '_id: ' . $case => [['_id' => $value], '_id'];
        }
        yield '_id отсутствует' => [['_id' => self::MISSING], '_id'];
        yield 'parent_id ноль' => [['parent_id' => 0, 'path' => [7]], 'parent_id'];
        yield 'parent_id строка' => [['parent_id' => 'x'], 'parent_id'];
        yield 'parent_id массив' => [['parent_id' => []], 'parent_id'];
        foreach (['отсутствует' => self::MISSING, 'null' => null, 'пустое' => '', 'пробелы' => '  ', 'число' => 123] as $case => $value) {
            yield 'name: ' . $case => [['name' => $value], 'name'];
        }
        yield 'is_archived yes' => [['is_archived' => 'yes'], 'is_archived'];
        yield 'is_archived null' => [['is_archived' => null], 'is_archived'];
        foreach (['отсутствует' => self::MISSING, 'пустой' => [], 'строка' => '1,2', 'нечисловой элемент' => [1, 'x']] as $case => $value) {
            yield 'path: ' . $case => [['path' => $value], 'path'];
        }
        foreach (['отрицательный' => -1, 'буквы' => 'abc', 'дробный' => 1.5] as $case => $value) {
            yield 'offer_count: ' . $case => [['offer_count' => $value], 'offer_count'];
        }
    }

    private const MISSING = "\0missing";

    public function testOptionalFields(): void
    {
        $withoutOptional = self::VALID;
        unset($withoutOptional['parent_id'], $withoutOptional['is_archived']);

        $category = self::mapOne($withoutOptional);

        self::assertTrue($category->isRoot());
        self::assertFalse($category->isArchived());
        self::assertNull($category->offerCount());
        self::assertNull(self::mapOne(array_merge(self::VALID, ['offer_count' => null]))->offerCount());
        self::assertSame(0, self::mapOne(array_merge(self::VALID, ['offer_count' => 0]))->offerCount());
        self::assertSame(0, self::mapOne(array_merge(self::VALID, ['offer_count' => '0']))->offerCount());
    }

    /**
     * @param bool|int|string $value
     */
    #[DataProvider('archivedFlags')]
    public function testArchivedFlag(bool|int|string $value, bool $expected): void
    {
        self::assertSame($expected, self::mapOne(array_merge(self::VALID, ['is_archived' => $value]))->isArchived());
    }

    /**
     * @return iterable<string, array{bool|int|string, bool}>
     */
    public static function archivedFlags(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield '1' => [1, true];
        yield '0' => [0, false];
        yield '"1"' => ['1', true];
        yield '"0"' => ['0', false];
    }

    public function testDomainInvariantViolationIsWrapped(): void
    {
        try {
            self::mapOne(array_merge(self::VALID, ['path' => [3]]));
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
            self::assertStringContainsString('7', $e->getMessage());
        }
    }

    public function testDuplicateIdsAreRejected(): void
    {
        $this->expectException(UnexpectedResponseException::class);

        (new CategoryMapper())->toTree(['7' => self::VALID, 'copy' => self::VALID]);
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        self::assertSame('Категория', self::mapOne(array_merge(self::VALID, ['icon' => 'x.png', 'extra' => ['a' => 1]]))->name());
    }

    private static function fixtureTree(): CategoryTree
    {
        return (new CategoryMapper())->toTree(Fixtures::json('categories/categories.json'));
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function mapOne(array $record): Category
    {
        $record = array_filter($record, static fn (mixed $value): bool => $value !== self::MISSING);

        return (new CategoryMapper())->toTree([$record])->all()[0];
    }

    /**
     * @param list<CategoryId> $ids
     *
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (CategoryId $id): int => $id->value(), $ids);
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
