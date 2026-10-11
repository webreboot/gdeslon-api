<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class SearchResultTest extends TestCase
{
    public function testEmptyResult(): void
    {
        $result = new SearchResult(new SearchCriteria(), [], 0);

        self::assertCount(0, $result);
        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->total());
        self::assertSame([], $result->skipped());
        self::assertFalse($result->hasNextPage());
        self::assertNull($result->nextPage());
    }

    public function testFullPageWithUnknownTotalHasNextPage(): void
    {
        $criteria = new SearchCriteria(query: 'платье', merchants: [107054], limit: 3);
        $result = new SearchResult($criteria, self::offers(3), null);

        $next = $result->nextPage();

        self::assertNull($result->total());
        self::assertTrue($result->hasNextPage());
        self::assertNotNull($next);
        self::assertSame(2, $next->page());
        self::assertSame('платье', $next->query());
        self::assertSame($criteria->merchants(), $next->merchants());
    }

    public function testKnownTotalStopsPagination(): void
    {
        self::assertTrue((new SearchResult(new SearchCriteria(limit: 10, page: 532), self::offers(10), 5322))->hasNextPage());
        self::assertFalse((new SearchResult(new SearchCriteria(limit: 10, page: 533), self::offers(10), 5322))->hasNextPage());
    }

    public function testWindowStopsPagination(): void
    {
        $result = new SearchResult(new SearchCriteria(limit: 10, page: 1000), self::offers(10), null);

        self::assertFalse($result->hasNextPage());
        self::assertNull($result->nextPage());
    }

    public function testPartialPageIsLast(): void
    {
        self::assertFalse((new SearchResult(new SearchCriteria(limit: 10), self::offers(9), null))->hasNextPage());

        $withSkipped = new SearchResult(new SearchCriteria(limit: 10), self::offers(9), null, ['оффер «1»: нет поля url']);
        self::assertTrue($withSkipped->hasNextPage(), 'пропущенный оффер тоже занимал место на странице');
        self::assertSame(['оффер «1»: нет поля url'], $withSkipped->skipped());
    }

    public function testIterationKeepsOrderAndAllowsDuplicateIds(): void
    {
        $offers = [self::offer('5'), self::offer('3'), self::offer('5')];
        $result = new SearchResult(new SearchCriteria(), $offers, null);

        self::assertSame($offers, $result->offers());
        self::assertSame($offers, iterator_to_array($result, false));
        self::assertCount(3, $result);
        self::assertFalse($result->isEmpty());
    }

    public function testCriteriaIsKept(): void
    {
        $criteria = new SearchCriteria(query: 'x');

        self::assertSame($criteria, (new SearchResult($criteria, [], null))->criteria());
    }

    /**
     * @return list<Offer>
     */
    private static function offers(int $count): array
    {
        return array_map(static fn (int $i): Offer => self::offer((string) $i), range(1, $count));
    }

    private static function offer(string $id): Offer
    {
        return new Offer($id, new MerchantId(1), 'Товар ' . $id, new Money('1', 'RUR'), 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1');
    }

    public function testEffectiveLimitDefaultsToLimit(): void
    {
        $result = new SearchResult(new SearchCriteria(limit: 10), self::offers(10), null);

        self::assertSame(10, $result->effectiveLimit());
        self::assertFalse($result->isDegraded());
        self::assertFalse((new SearchResult(new SearchCriteria(limit: 10), [], 0, [], 10))->isDegraded());
    }

    public function testDegradedResultKeepsOriginalCriteriaAndNextPage(): void
    {
        $criteria = new SearchCriteria(query: 'платье', limit: 10, page: 2);
        $result = new SearchResult($criteria, self::offers(10), 8140, [], 5);

        $next = $result->nextPage();

        self::assertSame($criteria, $result->criteria());
        self::assertSame(5, $result->effectiveLimit());
        self::assertTrue($result->isDegraded());
        self::assertNotNull($next);
        self::assertSame(3, $next->page());
        self::assertSame(10, $next->limit());
        self::assertSame('платье', $next->query());
    }

    public function testDegradedShortPageIsLast(): void
    {
        self::assertFalse((new SearchResult(new SearchCriteria(limit: 10), self::offers(7), null, [], 5))->hasNextPage());
        self::assertTrue((new SearchResult(new SearchCriteria(limit: 10), self::offers(9), null, ['оффер «1»: нет поля url'], 5))->hasNextPage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidEffectiveLimits(): iterable
    {
        yield 'ноль' => [0];
        yield 'отрицательный' => [-5];
        yield 'больше limit' => [20];
        yield 'не делитель limit' => [3];
    }

    #[DataProvider('invalidEffectiveLimits')]
    public function testRejectsInvalidEffectiveLimit(int $effectiveLimit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('limit 10, получено %d', $effectiveLimit));

        new SearchResult(new SearchCriteria(limit: 10), [], null, [], $effectiveLimit);
    }
}
