<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class SearchCriteriaTest extends TestCase
{
    public function testDefaults(): void
    {
        $criteria = new SearchCriteria();

        self::assertNull($criteria->query());
        self::assertSame([], $criteria->merchants());
        self::assertSame([], $criteria->excludedMerchants());
        self::assertSame([], $criteria->categories());
        self::assertSame([], $criteria->excludedCategories());
        self::assertSame([], $criteria->articles());
        self::assertSame(10, $criteria->limit());
        self::assertSame(1, $criteria->page());
        self::assertNull($criteria->sort());
        self::assertNull($criteria->parkedDomain());
        self::assertSame(['price', 'partner_benefit', 'newest'], array_map(static fn (OfferSort $s): string => $s->value, OfferSort::cases()));
    }

    public function testQueryIsPassedAsIs(): void
    {
        self::assertSame('iphone -pink', (new SearchCriteria(query: '  iphone -pink '))->query());
        self::assertSame('ZARA OR (MASSIMO AND DUTTI)', (new SearchCriteria(query: 'ZARA OR (MASSIMO AND DUTTI)'))->query());
        self::assertSame('платье', (new SearchCriteria(query: 'платье'))->query());
        self::assertNull((new SearchCriteria(query: '   '))->query());
    }

    public function testIdsAreNormalized(): void
    {
        $criteria = new SearchCriteria(
            merchants: [107054, new MerchantId(111211), 107054],
            excludedMerchants: [82012],
            categories: [26, new CategoryId(349), 26],
            excludedCategories: [new CategoryId(1)],
        );

        self::assertSame([107054, 111211], array_map(static fn (MerchantId $id): int => $id->value(), $criteria->merchants()));
        self::assertSame([82012], array_map(static fn (MerchantId $id): int => $id->value(), $criteria->excludedMerchants()));
        self::assertSame([26, 349], array_map(static fn (CategoryId $id): int => $id->value(), $criteria->categories()));
        self::assertSame([1], array_map(static fn (CategoryId $id): int => $id->value(), $criteria->excludedCategories()));
    }

    /**
     * @param \Closure(): SearchCriteria $create
     */
    #[DataProvider('invalidCriteria')]
    public function testRejectsInvalidCriteria(\Closure $create, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /**
     * @return iterable<string, array{\Closure(): SearchCriteria, string}>
     */
    public static function invalidCriteria(): iterable
    {
        yield 'ID магазина 0' => [static fn () => new SearchCriteria(merchants: [0]), '0'];
        yield 'ID категории -1' => [static fn () => new SearchCriteria(excludedCategories: [-1]), '-1'];
        yield 'магазин и включён, и исключён' => [static fn () => new SearchCriteria(merchants: [107054, 1], excludedMerchants: [107054]), '107054'];
        yield 'категория и включена, и исключена' => [static fn () => new SearchCriteria(categories: [26], excludedCategories: [26]), '26'];
        yield 'limit 0' => [static fn () => new SearchCriteria(limit: 0), 'limit'];
        yield 'limit 101' => [static fn () => new SearchCriteria(limit: 101), 'limit'];
        yield 'limit -1' => [static fn () => new SearchCriteria(limit: -1), 'limit'];
        yield 'page 0' => [static fn () => new SearchCriteria(page: 0), 'page'];
        yield 'page -1' => [static fn () => new SearchCriteria(page: -1), 'page'];
        yield 'глубже окна (1001×10)' => [static fn () => new SearchCriteria(limit: 10, page: 1001), '10 000'];
        yield 'глубже окна (101×100)' => [static fn () => new SearchCriteria(limit: 100, page: 101), '10 000'];
        yield 'пустой артикул' => [static fn () => new SearchCriteria(articles: ['']), 'артикул'];
        yield 'артикул из пробелов' => [static fn () => new SearchCriteria(articles: [' ']), 'артикул'];
        yield 'артикул с запятой' => [static fn () => new SearchCriteria(articles: ['a,b']), 'a,b'];
        yield 'домен без схемы' => [static fn () => new SearchCriteria(parkedDomain: 'example.com'), 'example.com'];
        yield 'домен ftp' => [static fn () => new SearchCriteria(parkedDomain: 'ftp://x'), 'ftp://x'];
        yield 'домен с путём' => [static fn () => new SearchCriteria(parkedDomain: 'http://x/path'), 'http://x/path'];
        yield 'домен с query' => [static fn () => new SearchCriteria(parkedDomain: 'http://x?a=1'), 'http://x?a=1'];
    }

    public function testWindowEdgesAreAllowed(): void
    {
        self::assertSame(1000, (new SearchCriteria(limit: 10, page: 1000))->page());
        self::assertSame(100, (new SearchCriteria(limit: 100, page: 100))->page());
        self::assertSame(1, (new SearchCriteria(limit: 1))->limit());
    }

    public function testArticlesAreTrimmedAndDeduplicated(): void
    {
        self::assertSame(['578237', '434967'], (new SearchCriteria(articles: [' 578237 ', '434967', '578237']))->articles());
    }

    public function testParkedDomain(): void
    {
        self::assertSame('http://example.com', (new SearchCriteria(parkedDomain: 'http://example.com'))->parkedDomain());
        self::assertSame('https://my.site.ru', (new SearchCriteria(parkedDomain: 'https://my.site.ru'))->parkedDomain());
        self::assertSame('https://example.com', (new SearchCriteria(parkedDomain: 'https://example.com/'))->parkedDomain());
    }

    public function testWithPage(): void
    {
        $criteria = new SearchCriteria(query: 'платье', merchants: [107054], limit: 20, sort: OfferSort::Price);

        $next = $criteria->withPage(2);

        self::assertNotSame($criteria, $next);
        self::assertSame(1, $criteria->page());
        self::assertSame(2, $next->page());
        self::assertSame('платье', $next->query());
        self::assertSame(20, $next->limit());
        self::assertSame(OfferSort::Price, $next->sort());
        self::assertSame($criteria->merchants(), $next->merchants());

        $this->expectException(InvalidArgumentException::class);
        (new SearchCriteria(limit: 10))->withPage(1001);
    }
}
