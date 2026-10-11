<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Shared\DataOrigin;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpCategoryRepository;
use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Tests\Support\FakeRangeServer;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;
use Webreboot\GdeSlon\Tests\Support\InMemoryCacheStore;

final class HttpCategoryRepositoryCacheTest extends TestCase
{
    private const URL = HttpCategoryRepository::DEFAULT_URL;
    private const ETAG = '"6724af75-26edb"';
    private const LAST_MODIFIED = 'Fri, 01 Nov 2024 10:37:41 GMT';
    private const TTL = 3600;
    private const NEW_BODY = '{"1":{"_id":1,"parent_id":null,"name":"Новая","is_archived":false,"path":[1]}}';

    private FakeHttpTransport $transport;
    private InMemoryCacheStore $store;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->transport = new FakeHttpTransport();
        $this->store = new InMemoryCacheStore();
        $this->clock = FrozenClock::at('2026-10-07 12:00:00');
    }

    public function testFirstLoadIsUnconditionalAndCached(): void
    {
        $this->transport->willReturn(200, self::fixture(), self::validators());

        $tree = $this->repository()->all();

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Fetched, $tree->origin());
        self::assertNull($this->transport->lastRequest()->header('If-None-Match'));
        self::assertNull($this->transport->lastRequest()->header('If-Modified-Since'));
        $document = $this->cached();
        self::assertNotNull($document);
        self::assertSame(self::fixture(), $document->body());
        self::assertSame(self::ETAG, $document->etag());
        self::assertSame(self::LAST_MODIFIED, $document->lastModified());
        self::assertSame($this->clock->now()->getTimestamp(), $document->storedAt()->getTimestamp());
    }

    public function testFreshEntryNeedsNoRequest(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL - 1);

        $tree = $this->repository()->all();

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Cached, $tree->origin());
        self::assertSame([], $this->transport->requests());
    }

    public function testStaleEntryIsRevalidated(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL);
        $this->transport->willReturn(304, '', self::validators());
        $repository = $this->repository();

        $tree = $repository->all();

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Revalidated, $tree->origin());
        $request = $this->transport->lastRequest();
        self::assertSame(self::ETAG, $request->header('If-None-Match'));
        self::assertSame(self::LAST_MODIFIED, $request->header('If-Modified-Since'));
        self::assertSame($this->clock->now()->getTimestamp(), $this->cached()?->storedAt()->getTimestamp());

        $again = $repository->all();
        self::assertCount(23, $again, 'после 304 запись снова свежая');
        self::assertSame(DataOrigin::Cached, $again->origin());
        self::assertCount(1, $this->transport->requests());
    }

    public function testStaleEntryIsReplacedByNewVersion(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL);
        $this->transport->willReturn(200, self::NEW_BODY, ['ETag' => ['"new"']]);

        $tree = $this->repository()->all();

        self::assertSame('Новая', $tree->get(1)->name());
        self::assertSame(DataOrigin::Fetched, $tree->origin());
        $document = $this->cached();
        self::assertNotNull($document);
        self::assertSame(self::NEW_BODY, $document->body());
        self::assertSame('"new"', $document->etag());
    }

    public function testWithoutCacheOriginIsFetched(): void
    {
        $this->transport->willReturn(200, self::fixture(), self::validators());

        $tree = (new HttpCategoryRepository(new ApiClient($this->transport), self::URL))->all();

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Fetched, $tree->origin());
        self::assertSame([], $this->transport->lastRequest()->headers());
    }

    public function testConditionalHeadersFollowStoredValidators(): void
    {
        $this->storeEntry(self::fixture(), null, self::LAST_MODIFIED, ageSeconds: self::TTL);
        $this->transport->willReturn(304, '');
        $this->repository()->all();
        self::assertNull($this->transport->lastRequest()->header('If-None-Match'));
        self::assertSame(self::LAST_MODIFIED, $this->transport->lastRequest()->header('If-Modified-Since'));

        $this->setUp();
        $this->storeEntry(self::fixture(), null, null, ageSeconds: self::TTL);
        $this->transport->willReturn(200, self::fixture());
        $this->repository()->all();
        self::assertSame([], $this->transport->lastRequest()->headers());
    }

    public function testNotModifiedWithoutCachedEntryIsUnexpected(): void
    {
        $this->transport->willReturn(304, '');

        $this->expectException(UnexpectedResponseException::class);

        $this->repository()->all();
    }

    #[DataProvider('outages')]
    public function testStaleEntryIsServedWhenApiIsUnavailable(?TransportException $exception, int $status): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL * 10);
        $before = $this->store->all();
        $exception === null ? $this->transport->willReturn($status, 'busy') : $this->transport->willThrow($exception);

        $tree = $this->repository()->all();

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Stale, $tree->origin());
        self::assertSame($before, $this->store->all());
    }

    /**
     * @return iterable<string, array{?TransportException, int}>
     */
    public static function outages(): iterable
    {
        yield 'таймаут' => [new TimeoutException('таймаут', 'GET', self::URL, 28), 0];
        yield 'нет соединения' => [new TransportException('отказ', 'GET', self::URL, 7), 0];
        yield 'ошибка загрузки частями' => [new TransportException('GET ' . self::URL . ': ресурс изменился во время загрузки частями', 'GET', self::URL), 0];
        yield 'HTTP 500' => [null, 500];
        yield 'HTTP 503' => [null, 503];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('clientErrors')]
    public function testClientErrorsAreNotHiddenByCache(int $status, string $expected): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL * 10);
        $this->transport->willReturn($status, 'нет');

        $this->expectException($expected);

        $this->repository()->all();
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function clientErrors(): iterable
    {
        yield '404' => [404, HttpException::class];
        yield '403' => [403, AuthenticationException::class];
    }

    public function testOutageCostsOneRequestPerCallWithRangeTransport(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL * 10);
        $before = $this->store->all();
        $server = (new FakeRangeServer(self::fixture()))->failPart(0, new TimeoutException('таймаут', 'GET', self::URL, 28), 100);
        $repository = $this->rangeRepository($server);

        self::assertSame(DataOrigin::Stale, $repository->all()->origin());
        self::assertCount(23, $repository->all());
        self::assertCount(2, $server->requests());
        self::assertSame($before, $this->store->all());
    }

    public function testOutageWithoutCacheIsRethrown(): void
    {
        $timeout = new TimeoutException('таймаут', 'GET', self::URL, 28);
        $this->transport->willThrow($timeout);

        try {
            $this->repository()->all();
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e);
        }
    }

    public function testBrokenResponseServesStaleCopyAndNeverReplacesCache(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL);
        $before = $this->store->all();
        $this->transport->willReturn(200, '{"1":', ['ETag' => ['"new"']]);

        $tree = $this->repository()->all();

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Stale, $tree->origin());
        self::assertSame($before, $this->store->all());
    }

    public function testBrokenResponseWithoutCacheIsAnError(): void
    {
        $this->transport->willReturn(200, '{"1":');

        $this->expectException(UnexpectedResponseException::class);

        $this->repository()->all();
    }

    public function testUnparseableCachedBodyIsMiss(): void
    {
        $this->storeEntry('{"1":5}', self::ETAG, self::LAST_MODIFIED, ageSeconds: 0);
        $this->transport->willReturn(200, self::fixture(), self::validators());

        self::assertCount(23, $this->repository()->all());
        self::assertNull($this->transport->lastRequest()->header('If-None-Match'));
        self::assertSame(self::fixture(), $this->cached()?->body());
    }

    public function testFailedCacheWriteDoesNotBreakLoading(): void
    {
        $this->store->failWrites();
        $this->transport->willReturn(200, self::fixture(), self::validators());

        self::assertCount(23, $this->repository()->all());
    }

    public function testRefreshIgnoresFreshnessAndRevalidates(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: 10);
        $this->transport->willReturn(304, '', self::validators());
        $repository = $this->repository();

        $tree = $repository->all(refresh: true);

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Revalidated, $tree->origin());
        $request = $this->transport->lastRequest();
        self::assertSame(self::ETAG, $request->header('If-None-Match'));
        self::assertSame(self::LAST_MODIFIED, $request->header('If-Modified-Since'));
        self::assertSame($this->clock->now()->getTimestamp(), $this->cached()?->storedAt()->getTimestamp());

        self::assertSame(DataOrigin::Cached, $repository->all()->origin());
        self::assertCount(1, $this->transport->requests());

        $this->setUp();
        $this->storeEntry(self::fixture(), self::ETAG, null, ageSeconds: 0);
        $this->transport->willReturn(304, '');
        self::assertSame(DataOrigin::Revalidated, $this->repository()->all(refresh: true)->origin());
        self::assertSame(['If-None-Match' => self::ETAG], $this->transport->lastRequest()->headers(), 'только имеющиеся валидаторы');
    }

    public function testRefreshReplacesCopyWithNewVersion(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: 0);
        $this->transport->willReturn(200, self::NEW_BODY, ['ETag' => ['"new"']]);

        $tree = $this->repository()->all(refresh: true);

        self::assertSame('Новая', $tree->get(1)->name());
        self::assertSame(DataOrigin::Fetched, $tree->origin());
        self::assertSame(self::ETAG, $this->transport->lastRequest()->header('If-None-Match'));
        $document = $this->cached();
        self::assertNotNull($document);
        self::assertSame(self::NEW_BODY, $document->body());
        self::assertSame('"new"', $document->etag());
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param class-string<GdeSlonException>                 $expected
     */
    #[DataProvider('refreshFailures')]
    public function testRefreshFailureThrowsAndKeepsCopy(\Closure $response, string $expected, int $ageSeconds): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: $ageSeconds);
        $before = $this->store->all();
        $response($this->transport);

        try {
            $this->repository()->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (GdeSlonException $e) {
            self::assertSame($expected, $e::class);
        }

        self::assertSame($before, $this->store->all());
        self::assertCount(1, $this->transport->requests());
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, class-string<GdeSlonException>, int}>
     */
    public static function refreshFailures(): iterable
    {
        $failures = [
            'таймаут' => [static fn (FakeHttpTransport $t) => $t->willThrow(new TimeoutException('таймаут', 'GET', self::URL, 28)), TimeoutException::class],
            'отказ' => [static fn (FakeHttpTransport $t) => $t->willThrow(new TransportException('отказ', 'GET', self::URL, 7)), TransportException::class],
            '500' => [static fn (FakeHttpTransport $t) => $t->willReturn(500, 'busy'), HttpException::class],
            '503' => [static fn (FakeHttpTransport $t) => $t->willReturn(503, 'busy'), HttpException::class],
            '404' => [static fn (FakeHttpTransport $t) => $t->willReturn(404, 'нет'), HttpException::class],
            '403' => [static fn (FakeHttpTransport $t) => $t->willReturn(403, 'нет'), AuthenticationException::class],
            '401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, 'нет'), AuthenticationException::class],
            'битое тело' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, '{"1":', ['ETag' => ['"new"']]), UnexpectedResponseException::class],
        ];
        foreach (['свежая копия' => 10, 'устаревшая копия' => self::TTL * 10] as $copy => $age) {
            foreach ($failures as $name => [$response, $expected]) {
                yield $name . ', ' . $copy => [$response, $expected, $age];
            }
        }
    }

    public function testRefreshFailureRethrowsSameTransportException(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: 10);
        $timeout = new TimeoutException('таймаут', 'GET', self::URL, 28);
        $this->transport->willThrow($timeout);

        try {
            $this->repository()->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e);
        }
    }

    public function testRefreshFailureLeavesCopyForNextOrdinaryCall(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: 10);
        $this->transport->willThrow(new TimeoutException('таймаут', 'GET', self::URL, 28));
        $repository = $this->repository();
        try {
            $repository->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (TimeoutException) {
        }

        self::assertSame(DataOrigin::Cached, $repository->all()->origin(), 'копия в пределах TTL');

        $this->clock->advance(self::TTL);
        $this->transport->willThrow(new TimeoutException('таймаут', 'GET', self::URL, 28));
        $tree = $repository->all();

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Stale, $tree->origin());
        self::assertCount(2, $this->transport->requests());
    }

    public function testRefreshWithoutCacheLoadsUnconditionally(): void
    {
        $this->transport->willReturn(200, self::fixture(), self::validators());

        $tree = (new HttpCategoryRepository(new ApiClient($this->transport), self::URL))->all(refresh: true);

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Fetched, $tree->origin());
        self::assertSame([], $this->transport->lastRequest()->headers());

        $this->transport->willReturn(200, self::fixture(), self::validators());
        self::assertSame(DataOrigin::Fetched, $this->repository()->all(refresh: true)->origin());
        self::assertSame([], $this->transport->lastRequest()->headers());
        self::assertSame(self::fixture(), $this->cached()?->body());

        $timeout = new TimeoutException('таймаут', 'GET', self::URL, 28);
        $this->transport->willThrow($timeout);
        try {
            (new HttpCategoryRepository(new ApiClient($this->transport), self::URL))->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e);
        }
    }

    public function testRefreshWithUnparseableCopyRequestsUnconditionallyAndRepairsCopy(): void
    {
        $this->storeEntry('{"1":5}', self::ETAG, self::LAST_MODIFIED, ageSeconds: 0);
        $this->transport->willReturn(200, self::fixture(), self::validators());

        $tree = $this->repository()->all(refresh: true);

        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Fetched, $tree->origin());
        self::assertSame([], $this->transport->lastRequest()->headers());
        self::assertSame(self::fixture(), $this->cached()?->body());
    }

    public function testRefreshWithUnparseableCopyAndOutageThrows(): void
    {
        $this->storeEntry('{"1":5}', self::ETAG, self::LAST_MODIFIED, ageSeconds: 0);
        $before = $this->store->all();
        $timeout = new TimeoutException('таймаут', 'GET', self::URL, 28);
        $this->transport->willThrow($timeout);

        try {
            $this->repository()->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e);
        }
        self::assertSame($before, $this->store->all());
    }

    public function testRefreshNotModifiedWithoutCopyIsUnexpected(): void
    {
        $this->transport->willReturn(304, '');
        try {
            $this->repository()->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException) {
        }

        $this->storeEntry('{"1":5}', self::ETAG, self::LAST_MODIFIED, ageSeconds: 0);
        $before = $this->store->all();
        $this->transport->willReturn(304, '');
        try {
            $this->repository()->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException) {
        }
        self::assertSame($before, $this->store->all());
    }

    public function testRefreshWithRangeTransportOutageThrows(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: 10);
        $before = $this->store->all();
        $server = (new FakeRangeServer(self::fixture()))->failPart(0, new TimeoutException('таймаут', 'GET', self::URL, 28), 100);

        try {
            $this->rangeRepository($server)->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (TransportException) {
        }

        self::assertCount(1, $server->requests());
        self::assertSame(self::ETAG, $server->requests()[0]->header('If-None-Match'));
        self::assertSame($before, $this->store->all());
    }

    public function testRefreshFailedCacheWriteStillReturnsData(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: 10);
        $before = $this->store->all();
        $this->store->failWrites();
        $this->transport->willReturn(200, self::NEW_BODY, ['ETag' => ['"new"']])->willReturn(304, '', self::validators());
        $repository = $this->repository();

        $tree = $repository->all(refresh: true);
        self::assertSame('Новая', $tree->get(1)->name());
        self::assertSame(DataOrigin::Fetched, $tree->origin());

        $revalidated = $repository->all(refresh: true);
        self::assertCount(23, $revalidated);
        self::assertSame(DataOrigin::Revalidated, $revalidated->origin());
        self::assertSame($before, $this->store->all());
    }

    private function repository(): HttpCategoryRepository
    {
        return new HttpCategoryRepository(
            new ApiClient($this->transport),
            self::URL,
            new DocumentCache($this->store, $this->clock, self::TTL),
        );
    }

    private function rangeRepository(FakeRangeServer $server): HttpCategoryRepository
    {
        return new HttpCategoryRepository(
            new ApiClient(new RangeTransport($server)),
            self::URL,
            new DocumentCache($this->store, $this->clock, self::TTL),
        );
    }

    private function storeEntry(string $body, ?string $etag, ?string $lastModified, int $ageSeconds): void
    {
        $storedAt = $this->clock->now()->modify(sprintf('-%d seconds', $ageSeconds));
        $this->store->set(DocumentCache::key(self::URL), (new CachedDocument($body, $etag, $lastModified, $storedAt))->toString());
    }

    private function cached(): ?CachedDocument
    {
        $value = $this->store->get(DocumentCache::key(self::URL));

        return $value === null ? null : CachedDocument::fromString($value);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function validators(): array
    {
        return ['ETag' => [self::ETAG], 'Last-Modified' => [self::LAST_MODIFIED]];
    }

    private static function fixture(): string
    {
        return Fixtures::read('categories/categories.json');
    }
}
