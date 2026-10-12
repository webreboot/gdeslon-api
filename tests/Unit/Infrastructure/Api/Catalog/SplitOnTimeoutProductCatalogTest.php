<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\SplitOnTimeoutProductCatalog;
use Webreboot\GdeSlon\Testing\FakeProductCatalog;

final class SplitOnTimeoutProductCatalogTest extends TestCase
{
    private const URL = 'https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=10&p=1';

    public function testPassesResultThroughWithoutTimeout(): void
    {
        $page = new SearchResult(new SearchCriteria(query: 'платье', limit: 10), self::offers(1, 10), 8140);
        $inner = new FakeProductCatalog([static fn (): SearchResult => $page]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(query: 'платье', limit: 10));

        self::assertSame($page, $result);
        self::assertCount(1, $inner->criteria());
        self::assertSame(10, $result->effectiveLimit());
        self::assertFalse($result->isDegraded());
    }

    public function testSplitsTimedOutPageIntoSmallerPagesOfSameWindow(): void
    {
        $criteria = new SearchCriteria(query: 'платье', merchants: [107054], limit: 10);
        $inner = new FakeProductCatalog([
            self::timeout(),
            self::page(1, 5, 8140, ['оффер «x»: нет поля url']),
            self::page(6, 5, 8140, ['оффер «y»: цена 0']),
        ]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search($criteria);

        self::assertSame([[10, 1], [5, 1], [5, 2]], self::requested($inner));
        self::assertSame('платье', $inner->criteria()[1]->query());
        self::assertSame($criteria->merchants(), $inner->criteria()[2]->merchants());
        self::assertSame(self::ids(1, 10), self::resultIds($result));
        self::assertSame(['оффер «x»: нет поля url', 'оффер «y»: цена 0'], $result->skipped());
        self::assertSame($criteria, $result->criteria());
        self::assertSame(5, $result->effectiveLimit());
        self::assertTrue($result->isDegraded());
        self::assertSame(8140, $result->total());
        $next = $result->nextPage();
        self::assertNotNull($next);
        self::assertSame([10, 2], [$next->limit(), $next->page()]);
    }

    /**
     * @return iterable<string, array{int, int, list<array{int, int}>}>
     */
    public static function divisors(): iterable
    {
        yield '12 по 5 → по 4' => [12, 5, [[12, 1], [4, 1], [4, 2], [4, 3]]];
        yield '10 по 3 → по 2' => [10, 3, [[10, 1], [2, 1], [2, 2], [2, 3], [2, 4], [2, 5]]];
        yield '7 по 5 → по 1' => [7, 5, [[7, 1], [1, 1], [1, 2], [1, 3], [1, 4], [1, 5], [1, 6], [1, 7]]];
        yield '20 по 10 → по 10' => [20, 10, [[20, 1], [10, 1], [10, 2]]];
    }

    /**
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('divisors')]
    public function testEffectiveLimitIsLargestDivisorNotAboveFallback(int $limit, int $fallback, array $expected): void
    {
        $pages = [self::timeout()];
        for ($k = 1; $k < count($expected); $k++) {
            $pages[] = self::page(($k - 1) * $expected[1][0] + 1, $expected[1][0], null);
        }
        $inner = new FakeProductCatalog($pages);

        $result = (new SplitOnTimeoutProductCatalog($inner, $fallback))->search(new SearchCriteria(limit: $limit));

        self::assertSame($expected, self::requested($inner));
        self::assertSame($expected[1][0], $result->effectiveLimit());
        self::assertSame(self::ids(1, $limit), self::resultIds($result));
    }

    public function testHundredSplitsIntoTwentyPages(): void
    {
        $pages = [self::timeout()];
        for ($k = 1; $k <= 20; $k++) {
            $pages[] = self::page(($k - 1) * 5 + 1, 5, null);
        }
        $inner = new FakeProductCatalog($pages);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 100));

        self::assertCount(21, $inner->criteria());
        self::assertSame(self::ids(1, 100), self::resultIds($result));
    }

    public function testSubPagesCoverWindowOfLaterPage(): void
    {
        $inner = new FakeProductCatalog([self::timeout(), self::page(13, 4, null), self::page(17, 4, null), self::page(21, 4, null)]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 12, page: 2));

        self::assertSame([[12, 2], [4, 4], [4, 5], [4, 6]], self::requested($inner));
        self::assertSame(self::ids(13, 24), self::resultIds($result));
        self::assertSame(3, $result->nextPage()?->page());
    }

    public function testDeepWindowStaysWithinApiDepth(): void
    {
        $inner = new FakeProductCatalog([self::timeout(), self::page(9991, 5, null), self::page(9996, 5, null)]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10, page: 1000));

        self::assertSame([[10, 1000], [5, 1999], [5, 2000]], self::requested($inner));
        self::assertCount(10, $result);
        self::assertNull($result->nextPage());
    }

    public function testTotalComesFromFirstSubPage(): void
    {
        $inner = new FakeProductCatalog([self::timeout(), self::page(1, 5, 8140), self::page(6, 5, null)]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));

        self::assertSame(8140, $result->total());
    }

    public function testStopsEarlyOnShortSubPage(): void
    {
        $inner = new FakeProductCatalog([self::timeout(), self::page(1, 5, 7), self::page(6, 2, 7)]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 15));

        self::assertSame([[15, 1], [5, 1], [5, 2]], self::requested($inner));
        self::assertSame(self::ids(1, 7), self::resultIds($result));
        self::assertFalse($result->hasNextPage());
    }

    public function testSubPageFilledWithSkippedCountsAsFull(): void
    {
        $inner = new FakeProductCatalog([
            self::timeout(),
            self::page(1, 4, null, ['оффер «5»: нет поля url']),
            self::page(6, 5, null),
        ]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));

        self::assertCount(3, $inner->criteria());
        self::assertSame([...self::ids(1, 4), ...self::ids(6, 10)], self::resultIds($result));
        self::assertSame(['оффер «5»: нет поля url'], $result->skipped());
        self::assertTrue($result->hasNextPage());
    }

    public function testEmptyFirstSubPageEndsSplit(): void
    {
        $inner = new FakeProductCatalog([self::timeout(), self::page(1, 0, 0)]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10, page: 3));

        self::assertSame([[10, 3], [5, 5]], self::requested($inner));
        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->total());
        self::assertTrue($result->isDegraded());
        self::assertNull($result->nextPage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function limitsWithoutRoomToSplit(): iterable
    {
        yield 'равен запасному' => [5];
        yield 'меньше запасного' => [3];
        yield 'один' => [1];
    }

    #[DataProvider('limitsWithoutRoomToSplit')]
    public function testTimeoutWithoutRoomToSplitGetsHint(int $limit): void
    {
        $timeout = self::timeoutException();
        $inner = new FakeProductCatalog([static fn (): SearchResult => throw $timeout]);

        $e = self::catchTimeout(new SplitOnTimeoutProductCatalog($inner), new SearchCriteria(limit: $limit));

        self::assertSame(
            sprintf('GET %s: превышено время ожидания (cURL 28: Operation timed out). Если ответ поиска обрывается после ~16 КБ, уменьшите limit (сейчас %d)', self::URL, $limit),
            $e->getMessage(),
        );
        self::assertSame($timeout, $e->getPrevious());
        self::assertSame('GET', $e->method());
        self::assertSame(self::URL, $e->url());
        self::assertSame(28, $e->curlErrorCode());
        self::assertCount(1, $inner->criteria());
    }

    public function testDisabledFallbackOnlyAddsHint(): void
    {
        $timeout = self::timeoutException();
        $inner = new FakeProductCatalog([static fn (): SearchResult => throw $timeout]);

        $e = self::catchTimeout(new SplitOnTimeoutProductCatalog($inner, null), new SearchCriteria(limit: 100));

        self::assertStringEndsWith('. Если ответ поиска обрывается после ~16 КБ, уменьшите limit (сейчас 100)', $e->getMessage());
        self::assertSame($timeout, $e->getPrevious());
        self::assertCount(1, $inner->criteria());
    }

    public function testTooManySubPagesGetsHintToChooseMultipleOfFallback(): void
    {
        $timeout = self::timeoutException();
        $inner = new FakeProductCatalog([static fn (): SearchResult => throw $timeout]);

        $e = self::catchTimeout(new SplitOnTimeoutProductCatalog($inner), new SearchCriteria(limit: 97));

        self::assertStringEndsWith(
            '. Если ответ поиска обрывается после ~16 КБ, уменьшите limit (сейчас 97) или выберите кратный 5 (до 100) — тогда страница загрузится частями по 5',
            $e->getMessage(),
        );
        self::assertStringNotContainsString('из некоторых сетей', $e->getMessage());
        self::assertStringNotContainsString('Config::timeout', $e->getMessage());
        self::assertSame($timeout, $e->getPrevious());
        self::assertSame(28, $e->curlErrorCode());
        self::assertCount(1, $inner->criteria());
    }

    public function testTwentyOneSubPagesAreTooMany(): void
    {
        $timeout = self::timeoutException();
        $inner = new FakeProductCatalog([static fn (): SearchResult => throw $timeout]);

        $e = self::catchTimeout(new SplitOnTimeoutProductCatalog($inner), new SearchCriteria(limit: 84));

        self::assertStringEndsWith('уменьшите limit (сейчас 84) или выберите кратный 5 (до 100) — тогда страница загрузится частями по 5', $e->getMessage());
        self::assertSame($timeout, $e->getPrevious());
        self::assertCount(1, $inner->criteria());
    }

    public function testMultipleOfSmallFallbackIsBoundedByRequestLimit(): void
    {
        $inner = new FakeProductCatalog([self::timeout()]);

        $e = self::catchTimeout(new SplitOnTimeoutProductCatalog($inner, 2), new SearchCriteria(limit: 60));

        self::assertStringEndsWith('уменьшите limit (сейчас 60) или выберите кратный 2 (до 40) — тогда страница загрузится частями по 2', $e->getMessage());
        self::assertCount(1, $inner->criteria());
    }

    public function testTimedOutPartIsSplitAgain(): void
    {
        $criteria = new SearchCriteria(query: 'платье', limit: 10);
        $inner = self::window([[10, 1], [5, 1]]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search($criteria);

        self::assertSame([[10, 1], [5, 1], [1, 1], [1, 2], [1, 3], [1, 4], [1, 5], [5, 2]], self::requested($inner));
        self::assertSame('платье', $inner->criteria()[2]->query());
        self::assertSame(self::ids(1, 10), self::resultIds($result));
        self::assertSame(1, $result->effectiveLimit());
        self::assertTrue($result->isDegraded());
        self::assertSame($criteria, $result->criteria());
        $next = $result->nextPage();
        self::assertNotNull($next);
        self::assertSame([10, 2], [$next->limit(), $next->page()]);
    }

    public function testSecondPartSplitStaysInItsWindow(): void
    {
        $inner = self::window([[10, 1], [5, 2]]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));

        self::assertSame([[10, 1], [5, 1], [5, 2], [1, 6], [1, 7], [1, 8], [1, 9], [1, 10]], self::requested($inner));
        self::assertSame(self::ids(1, 10), self::resultIds($result));
        self::assertSame(1, $result->effectiveLimit());
    }

    /**
     * @return iterable<string, array{int, int, list<array{int, int}>, list<array{int, int}>, int}>
     */
    public static function splits(): iterable
    {
        yield '4 → 2' => [8, 4, [[8, 1], [4, 1]], [[8, 1], [4, 1], [2, 1], [2, 2], [4, 2]], 2];
        yield '6 → 3' => [12, 6, [[12, 1], [6, 1]], [[12, 1], [6, 1], [3, 1], [3, 2], [6, 2]], 3];
        yield '8 → 4' => [16, 8, [[16, 1], [8, 1]], [[16, 1], [8, 1], [4, 1], [4, 2], [8, 2]], 4];
        yield '9 → 3' => [18, 9, [[18, 1], [9, 1]], [[18, 1], [9, 1], [3, 1], [3, 2], [3, 3], [9, 2]], 3];
        yield '5 → 1' => [10, 5, [[10, 1], [5, 1]], [[10, 1], [5, 1], [1, 1], [1, 2], [1, 3], [1, 4], [1, 5], [5, 2]], 1];
        yield '3 → 1' => [9, 3, [[9, 1], [3, 1]], [[9, 1], [3, 1], [1, 1], [1, 2], [1, 3], [3, 2], [3, 3]], 1];
        yield '2 → 1' => [10, 2, [[10, 1], [2, 1]], [[10, 1], [2, 1], [1, 1], [1, 2], [2, 2], [2, 3], [2, 4], [2, 5]], 1];
    }

    /**
     * @param list<array{int, int}> $hanging
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('splits')]
    public function testPartSplitsByLargestDivisorNotAboveHalf(int $limit, int $fallback, array $hanging, array $expected, int $effectiveLimit): void
    {
        $inner = self::window($hanging);

        $result = (new SplitOnTimeoutProductCatalog($inner, $fallback))->search(new SearchCriteria(limit: $limit));

        self::assertSame($expected, self::requested($inner));
        self::assertSame(self::ids(1, $limit), self::resultIds($result));
        self::assertSame($effectiveLimit, $result->effectiveLimit());
    }

    public function testNestedSplitDownToOne(): void
    {
        $inner = self::window([[8, 1], [4, 1], [2, 1]]);

        $result = (new SplitOnTimeoutProductCatalog($inner, 4))->search(new SearchCriteria(limit: 8));

        self::assertSame([[8, 1], [4, 1], [2, 1], [1, 1], [1, 2], [2, 2], [4, 2]], self::requested($inner));
        self::assertSame(self::ids(1, 8), self::resultIds($result));
        self::assertSame(1, $result->effectiveLimit());
    }

    public function testSplitOnLaterPageKeepsAbsoluteOffsets(): void
    {
        $inner = self::window([[10, 3], [5, 5]]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10, page: 3));

        self::assertSame([[10, 3], [5, 5], [1, 21], [1, 22], [1, 23], [1, 24], [1, 25], [5, 6]], self::requested($inner));
        self::assertSame(self::ids(21, 30), self::resultIds($result));
        self::assertSame(4, $result->nextPage()?->page());
    }

    public function testSplitInDeepWindowStaysWithinApiDepth(): void
    {
        $inner = self::window([[10, 1000], [5, 1999]], 20000);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10, page: 1000));

        self::assertSame(
            [[10, 1000], [5, 1999], [1, 9991], [1, 9992], [1, 9993], [1, 9994], [1, 9995], [5, 2000]],
            self::requested($inner),
        );
        self::assertSame(self::ids(9991, 10000), self::resultIds($result));
        self::assertNull($result->nextPage());
    }

    public function testShortPieceAfterSplitEndsWindow(): void
    {
        $inner = self::window([[10, 1], [5, 1]], 2);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));

        self::assertSame([[10, 1], [5, 1], [1, 1], [1, 2], [1, 3]], self::requested($inner));
        self::assertSame(self::ids(1, 2), self::resultIds($result));
        self::assertFalse($result->hasNextPage());
    }

    public function testPieceFilledWithSkippedCountsAsFull(): void
    {
        $inner = new FakeProductCatalog([
            self::timeout(),
            self::timeout(),
            self::page(1, 1, null),
            self::page(2, 1, null),
            self::page(3, 0, null, ['оффер «3»: цена 0']),
            self::page(4, 1, null),
            self::page(5, 1, null),
            self::page(6, 5, null),
        ]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));

        self::assertCount(8, $inner->criteria());
        self::assertSame([...self::ids(1, 2), ...self::ids(4, 10)], self::resultIds($result));
        self::assertSame(['оффер «3»: цена 0'], $result->skipped());
        self::assertTrue($result->hasNextPage());
    }

    public function testTotalComesFromFirstLoadedPiece(): void
    {
        $inner = new FakeProductCatalog([
            self::timeout(),
            self::timeout(),
            self::page(1, 1, 8203),
            self::page(2, 1, null),
            self::page(3, 1, null),
            self::page(4, 1, null),
            self::page(5, 1, null),
            self::page(6, 5, null),
        ]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));

        self::assertSame(8203, $result->total());
    }

    public function testEmptyFirstPieceEndsSplit(): void
    {
        $inner = new FakeProductCatalog([self::timeout(), self::timeout(), self::page(1, 0, 0)]);

        $result = (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));

        self::assertSame([[10, 1], [5, 1], [1, 1]], self::requested($inner));
        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->total());
        self::assertNull($result->nextPage());
    }

    public function testPieceOfOneTimingOutFailsWholePage(): void
    {
        $inner = self::window([[10, 1], [5, 1], [1, 2]]);

        $e = self::catchTimeout(new SplitOnTimeoutProductCatalog($inner), new SearchCriteria(limit: 10));

        $url = 'https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=1&p=2';
        self::assertNotInstanceOf(ConnectTimeoutException::class, $e);
        self::assertSame(
            'Страница limit 10 не загрузилась: часть l=1&p=2 (оффер 2) превысила время ожидания, делить её дальше некуда '
            . '(под-запросов: 3 из 20). GET ' . $url . ': превышено время ожидания (cURL 28: Operation timed out)',
            $e->getMessage(),
        );
        $previous = $e->getPrevious();
        self::assertInstanceOf(TimeoutException::class, $previous);
        self::assertSame($url, $previous->url());
        self::assertSame($url, $e->url());
        self::assertSame('GET', $e->method());
        self::assertSame(28, $e->curlErrorCode());
        self::assertSame([[10, 1], [5, 1], [1, 1], [1, 2]], self::requested($inner));
    }

    public function testSplitBeyondBudgetFailsBeforeSpendingRequests(): void
    {
        $inner = self::window([[20, 1], [5, 1], [5, 2], [5, 3], [5, 4]]);

        $e = self::catchTimeout(new SplitOnTimeoutProductCatalog($inner), new SearchCriteria(limit: 20));

        self::assertSame(
            'Страница limit 20 не загрузилась: часть l=5&p=4 (офферы 16–20) превысила время ожидания, а на догрузку частями '
            . 'по 1 не хватает под-запросов (нужно ещё 5, осталось 1 из 20). '
            . 'GET https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=5&p=4: превышено время ожидания (cURL 28: Operation timed out)',
            $e->getMessage(),
        );
        self::assertCount(20, $inner->criteria());
    }

    public function testNoRoomToSplitWhenAllRequestsArePlanned(): void
    {
        $inner = self::window([[100, 1], [5, 1]]);

        $e = self::catchTimeout(new SplitOnTimeoutProductCatalog($inner), new SearchCriteria(limit: 100));

        self::assertStringContainsString(
            'часть l=5&p=1 (офферы 1–5) превысила время ожидания, а на догрузку частями по 1 не хватает под-запросов (нужно ещё 5, осталось 0 из 20)',
            $e->getMessage(),
        );
        self::assertCount(2, $inner->criteria());
    }

    public function testConnectTimeoutOnPieceAfterSplitPassesThrough(): void
    {
        $connectTimeout = self::connectTimeoutException('https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=1&p=2');
        $inner = new FakeProductCatalog([
            self::timeout(),
            self::timeout(),
            self::page(1, 1, null),
            static fn (): SearchResult => throw $connectTimeout,
        ]);

        try {
            (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));
            self::fail('ожидалось исключение');
        } catch (ConnectTimeoutException $e) {
            self::assertSame($connectTimeout, $e);
        }
        self::assertSame([[10, 1], [5, 1], [1, 1], [1, 2]], self::requested($inner));
    }

    public function testRateLimitOnPieceAfterSplitPassesThrough(): void
    {
        $rateLimit = new HttpException('GET …: HTTP 420', 420, 'GET', self::URL, 'Rate limit exceeded!');
        $inner = new FakeProductCatalog([
            self::timeout(),
            self::timeout(),
            self::page(1, 1, null),
            static fn (): SearchResult => throw $rateLimit,
        ]);

        try {
            (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));
            self::fail('ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame($rateLimit, $e);
        }
        self::assertCount(4, $inner->criteria());
    }

    /**
     * @return iterable<string, array{int, ?int}>
     */
    public static function connectTimeoutLimits(): iterable
    {
        yield 'есть куда делить' => [10, 5];
        yield 'по умолчанию' => [20, 5];
        yield 'limit 100' => [100, 5];
        yield 'равен запасному' => [5, 5];
        yield 'слишком много частей' => [97, 5];
        yield 'повтор выключен' => [100, null];
    }

    #[DataProvider('connectTimeoutLimits')]
    public function testConnectTimeoutOnFirstRequestIsRethrownAsIs(int $limit, ?int $fallback): void
    {
        $connectTimeout = self::connectTimeoutException(self::URL);
        $inner = new FakeProductCatalog([static fn (): SearchResult => throw $connectTimeout]);

        try {
            (new SplitOnTimeoutProductCatalog($inner, $fallback))->search(new SearchCriteria(query: 'платье', limit: $limit));
            self::fail('ожидалось исключение');
        } catch (ConnectTimeoutException $e) {
            self::assertSame($connectTimeout, $e);
            self::assertStringNotContainsString('16 КБ', $e->getMessage());
        }
        self::assertCount(1, $inner->criteria());
    }

    public function testConnectTimeoutDuringSplitPassesThrough(): void
    {
        $connectTimeout = self::connectTimeoutException('https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=5&p=2');
        $inner = new FakeProductCatalog([self::timeout(), self::page(1, 5, null), static fn (): SearchResult => throw $connectTimeout]);

        try {
            (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 15));
            self::fail('ожидалось исключение');
        } catch (ConnectTimeoutException $e) {
            self::assertSame($connectTimeout, $e);
        }
        self::assertSame([[15, 1], [5, 1], [5, 2]], self::requested($inner));
    }

    public function testOtherErrorDuringSplitPassesThrough(): void
    {
        $rateLimit = new HttpException('GET …: HTTP 420', 420, 'GET', self::URL, 'Rate limit exceeded!');
        $inner = new FakeProductCatalog([self::timeout(), self::page(1, 5, null), static fn (): SearchResult => throw $rateLimit]);

        try {
            (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 15));
            self::fail('ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame($rateLimit, $e);
        }
        self::assertCount(3, $inner->criteria());
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function otherErrors(): iterable
    {
        yield '420' => [new HttpException('HTTP 420', 420, 'GET', self::URL, 'Rate limit exceeded!')];
        yield '500' => [new HttpException('HTTP 500', 500, 'GET', self::URL, 'Something broken!')];
        yield '414' => [new HttpException('HTTP 414', 414, 'GET', self::URL, '')];
        yield 'токен' => [new AuthenticationException('HTTP 404', 404, 'GET', self::URL, '')];
        yield 'cURL 7' => [new TransportException('ошибка соединения', 'GET', self::URL, 7)];
        yield 'cURL 56' => [new TransportException('ошибка соединения', 'GET', self::URL, 56)];
        yield 'битый XML' => [new UnexpectedResponseException('не XML')];
        yield 'нет токена' => [new InvalidArgumentException('Поиск товаров требует токен XML API')];
    }

    #[DataProvider('otherErrors')]
    public function testNonTimeoutErrorOnFirstRequestIsNotRetried(\Throwable $error): void
    {
        $inner = new FakeProductCatalog([static fn (): SearchResult => throw $error]);

        try {
            (new SplitOnTimeoutProductCatalog($inner))->search(new SearchCriteria(limit: 10));
            self::fail('ожидалось исключение');
        } catch (\Throwable $e) {
            self::assertSame($error, $e);
        }
        self::assertCount(1, $inner->criteria());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidFallbackLimits(): iterable
    {
        yield 'ноль' => [0];
        yield 'отрицательный' => [-1];
        yield 'больше 100' => [101];
    }

    #[DataProvider('invalidFallbackLimits')]
    public function testRejectsInvalidFallbackLimit(int $fallback): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('searchFallbackLimit должен быть от 1 до 100 или null, получено %d', $fallback));

        new SplitOnTimeoutProductCatalog(new FakeProductCatalog([]), $fallback);
    }

    private static function catchTimeout(SplitOnTimeoutProductCatalog $catalog, SearchCriteria $criteria): TimeoutException
    {
        try {
            $catalog->search($criteria);
        } catch (TimeoutException $e) {
            return $e;
        }
        self::fail('ожидался TimeoutException');
    }

    private static function timeoutException(): TimeoutException
    {
        return new TimeoutException(
            sprintf('GET %s: превышено время ожидания (cURL 28: Operation timed out)', self::URL),
            'GET',
            self::URL,
            28,
        );
    }

    private static function connectTimeoutException(string $url): ConnectTimeoutException
    {
        return new ConnectTimeoutException(
            sprintf('GET %s: превышено время установки соединения (cURL 28: Connection timed out after 10001 milliseconds)', $url),
            'GET',
            $url,
            28,
        );
    }

    /**
     * @return \Closure(SearchCriteria): SearchResult
     */
    private static function timeout(): \Closure
    {
        return static fn (): SearchResult => throw self::timeoutException();
    }

    /**
     * @param list<array{int, int}> $hanging
     */
    private static function window(array $hanging, int $available = 1000): FakeProductCatalog
    {
        $respond = static function (SearchCriteria $criteria) use ($hanging, $available): SearchResult {
            $limit = $criteria->limit();
            $page = $criteria->page();
            if (in_array([$limit, $page], $hanging, true)) {
                $url = sprintf('https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=%d&p=%d', $limit, $page);

                throw new TimeoutException(sprintf('GET %s: превышено время ожидания (cURL 28: Operation timed out)', $url), 'GET', $url, 28);
            }
            $from = ($page - 1) * $limit + 1;
            $to = min($page * $limit, $available);

            return new SearchResult($criteria, $from > $to ? [] : self::offers($from, $to), $available);
        };

        return new FakeProductCatalog(array_fill(0, 30, $respond));
    }

    /**
     * @param list<string> $skipped
     *
     * @return \Closure(SearchCriteria): SearchResult
     */
    private static function page(int $from, int $count, ?int $total, array $skipped = []): \Closure
    {
        return static fn (SearchCriteria $criteria): SearchResult => new SearchResult(
            $criteria,
            $count === 0 ? [] : self::offers($from, $from + $count - 1),
            $total,
            $skipped,
        );
    }

    /**
     * @return list<Offer>
     */
    private static function offers(int $from, int $to): array
    {
        return array_map(
            static fn (string $id): Offer => new Offer($id, new MerchantId(1), 'Товар ' . $id, new Money('1', 'RUR'), 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1'),
            self::ids($from, $to),
        );
    }

    /**
     * @return list<string>
     */
    private static function ids(int $from, int $to): array
    {
        return array_map('strval', range($from, $to));
    }

    /**
     * @return list<string>
     */
    private static function resultIds(SearchResult $result): array
    {
        return array_map(static fn (Offer $offer): string => $offer->id(), $result->offers());
    }

    /**
     * @return list<array{int, int}>
     */
    private static function requested(FakeProductCatalog $catalog): array
    {
        return array_map(static fn (SearchCriteria $c): array => [$c->limit(), $c->page()], $catalog->criteria());
    }
}
