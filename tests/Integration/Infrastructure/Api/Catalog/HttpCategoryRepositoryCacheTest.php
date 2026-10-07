<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

        self::assertCount(23, $this->repository()->all());
        self::assertSame([], $this->transport->requests());
    }

    public function testStaleEntryIsRevalidated(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL);
        $this->transport->willReturn(304, '', self::validators());
        $repository = $this->repository();

        self::assertCount(23, $repository->all());
        $request = $this->transport->lastRequest();
        self::assertSame(self::ETAG, $request->header('If-None-Match'));
        self::assertSame(self::LAST_MODIFIED, $request->header('If-Modified-Since'));

        self::assertCount(23, $repository->all(), 'после 304 запись снова свежая');
        self::assertCount(1, $this->transport->requests());
    }

    public function testStaleEntryIsReplacedByNewVersion(): void
    {
        $this->storeEntry(self::fixture(), self::ETAG, self::LAST_MODIFIED, ageSeconds: self::TTL);
        $newBody = '{"1":{"_id":1,"parent_id":null,"name":"Новая","is_archived":false,"path":[1]}}';
        $this->transport->willReturn(200, $newBody, ['ETag' => ['"new"']]);

        $tree = $this->repository()->all();

        self::assertSame('Новая', $tree->get(1)->name());
        $document = $this->cached();
        self::assertNotNull($document);
        self::assertSame($newBody, $document->body());
        self::assertSame('"new"', $document->etag());
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

        self::assertCount(23, $this->repository()->all());
        self::assertSame($before, $this->store->all());
    }

    /**
     * @return iterable<string, array{?TransportException, int}>
     */
    public static function outages(): iterable
    {
        yield 'таймаут' => [new TimeoutException('таймаут', 'GET', self::URL, 28), 0];
        yield 'нет соединения' => [new TransportException('отказ', 'GET', self::URL, 7), 0];
        yield '503' => [null, 503];
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
        $server = (new FakeRangeServer(self::fixture()))->failPart(0, new TimeoutException('таймаут', 'GET', self::URL, 28), 100);
        $repository = new HttpCategoryRepository(
            new ApiClient(new RangeTransport($server)),
            self::URL,
            new DocumentCache($this->store, $this->clock, self::TTL),
        );

        self::assertCount(23, $repository->all());
        self::assertCount(23, $repository->all());
        self::assertCount(2, $server->requests());
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

        self::assertCount(23, $this->repository()->all());
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

    private function repository(): HttpCategoryRepository
    {
        return new HttpCategoryRepository(
            new ApiClient($this->transport),
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
