<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Yml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Yml\FeedCategoriesMapper;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class FeedCategoriesMapperTest extends TestCase
{
    public function testFixtureCategoriesBuildTreeWithPaths(): void
    {
        $tree = self::fixtureTree();

        self::assertCount(27, $tree);
        self::assertSame([1113, 1564], array_map(static fn (Category $c): int => $c->id()->value(), $tree->roots()));
        self::assertSame([1113, 1114, 1128], self::ids($tree->get(1128)->path()));
        self::assertSame('Нижнее белье', $tree->get(1128)->name());
        self::assertSame('Женская одежда', $tree->get(1114)->name());
        self::assertSame(1114, $tree->get(1128)->parentId()?->value());
        self::assertFalse($tree->get(1128)->isArchived());
        self::assertNull($tree->get(1128)->offerCount());
        self::assertSame([], $tree->orphans());
    }

    public function testEveryOfferCategoryOfFixtureIsInTree(): void
    {
        $tree = self::fixtureTree();
        preg_match_all('/gs_category_id="(\d+)"/', Fixtures::read('feeds/feed.xml'), $m);

        foreach ($m[1] as $id) {
            self::assertTrue($tree->has((int) $id), 'нет категории ' . $id);
        }
    }

    public function testMissingParentMakesOrphanWithoutError(): void
    {
        $tree = self::map('<category id="5" parent_id="9"><![CDATA[Платья]]></category><category id="6" parent_id="5">Мини</category>');

        self::assertSame([5], array_map(static fn (Category $c): int => $c->id()->value(), $tree->orphans()));
        self::assertSame([9, 5, 6], self::ids($tree->get(6)->path()));
    }

    public function testEmptyCategories(): void
    {
        self::assertCount(0, self::map(''));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenCategories(): iterable
    {
        yield 'цикл' => ['<category id="1" parent_id="2">a</category><category id="2" parent_id="1">b</category>', 'цикл'];
        yield 'сам себе родитель' => ['<category id="1" parent_id="1">a</category>', 'цикл'];
        yield 'дубль id' => ['<category id="1">a</category><category id="1">b</category>', 'дважды'];
        yield 'id не число' => ['<category id="a">a</category>', 'id'];
        yield 'parent_id не число' => ['<category id="1" parent_id="x">a</category>', 'parent_id'];
        yield 'пустое имя' => ['<category id="1"><![CDATA[ ]]></category>', 'название'];
    }

    #[DataProvider('brokenCategories')]
    public function testBrokenCategoriesRejected(string $xml, string $reason): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessageMatches('/^Категории выгрузки: .*' . preg_quote($reason, '/') . '/u');

        self::map($xml);
    }

    private static function fixtureTree(): CategoryTree
    {
        $xml = Fixtures::read('feeds/feed.xml');
        $start = strpos($xml, '<categories>');
        $end = strpos($xml, '</categories>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return self::mapElement(substr($xml, $start, $end - $start + strlen('</categories>')));
    }

    private static function map(string $inner): CategoryTree
    {
        return self::mapElement('<categories>' . $inner . '</categories>');
    }

    private static function mapElement(string $xml): CategoryTree
    {
        $element = simplexml_load_string($xml, null, LIBXML_NONET);
        self::assertNotFalse($element);

        return (new FeedCategoriesMapper())->map($element);
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
