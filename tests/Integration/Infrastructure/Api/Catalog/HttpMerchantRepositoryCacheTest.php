<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Shared\DataOrigin;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpCategoryRepository;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpMerchantRepository;
use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;
use Webreboot\GdeSlon\Infrastructure\Cache\CacheStore;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;
use Webreboot\GdeSlon\Tests\Support\InMemoryCacheStore;

final class HttpMerchantRepositoryCacheTest extends TestCase
{
    private const TOKEN = 'secret-token';
    private const ETAG = 'W/"dda383c2e1f6b0a4b2c3d4e5f6a7b8c9"';
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

    public function testFirstLoadIsCachedWithoutToken(): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);

        self::assertCount(9, $this->repository(self::TOKEN)->all());

        self::assertNull($this->transport->lastRequest()->header('If-None-Match'));
        self::assertCount(1, $this->store->all());
        $key = array_key_first($this->store->all());
        self::assertMatchesRegularExpression('/^gdeslon_[0-9a-f]{40}$/', (string) $key);
        self::assertNotSame(DocumentCache::key(HttpCategoryRepository::DEFAULT_URL), $key);
        foreach ($this->store->all() as $storedKey => $value) {
            self::assertStringNotContainsString(self::TOKEN, $storedKey);
            self::assertStringNotContainsString(self::TOKEN, $value);
        }
    }

    public function testFreshAndStaleEntries(): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]])->willReturn(304, '', ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        self::assertSame(DataOrigin::Fetched, $repository->all()->origin());

        $this->clock->advance(self::TTL - 1);
        $cached = $repository->all();
        self::assertCount(9, $cached);
        self::assertSame(DataOrigin::Cached, $cached->origin());
        self::assertCount(1, $this->transport->requests(), 'свежая запись — без запроса');

        $this->clock->advance(1);
        $revalidated = $repository->all();
        self::assertCount(9, $revalidated);
        self::assertSame(DataOrigin::Revalidated, $revalidated->origin());
        $request = $this->transport->lastRequest();
        self::assertSame(self::ETAG, $request->header('If-None-Match'));
        self::assertNull($request->header('If-Modified-Since'));
        self::assertSame('https://www.gdeslon.ru/api/users/shops.xml?api_token=' . self::TOKEN, $request->uri());

        $again = $repository->all();
        self::assertCount(9, $again);
        self::assertSame(DataOrigin::Cached, $again->origin());
        self::assertCount(2, $this->transport->requests(), 'после 304 запись снова свежая');
    }

    public function testStaleEntryIsReplacedByAnotherBackendVersion(): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $this->clock->advance(self::TTL);
        $reordered = self::shops(['117999', '105263']);
        $this->transport->willReturn(200, $reordered, ['ETag' => ['W/"other"']]);

        $list = $repository->all();
        self::assertCount(2, $list);
        self::assertSame(DataOrigin::Fetched, $list->origin());
        $stored = $this->stored();
        self::assertNotNull($stored);
        self::assertSame($reordered, $stored->body());
        self::assertSame('W/"other"', $stored->etag());
    }

    public function testTokensDoNotShareCache(): void
    {
        $this->transport->willReturn(200, self::fixture())->willReturn(200, self::shops(['105263']));

        self::assertCount(9, $this->repository('token-a')->all());
        self::assertCount(1, $this->repository('token-b')->all());

        self::assertCount(2, $this->transport->requests());
        self::assertNull($this->transport->lastRequest()->header('If-None-Match'));
        self::assertCount(2, $this->store->all());
    }

    #[DataProvider('outages')]
    public function testStaleEntryIsServedOnOutage(?TransportException $exception, int $status): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $before = $this->store->all();
        $this->clock->advance(self::TTL * 10);
        $exception === null ? $this->transport->willReturn($status, 'busy') : $this->transport->willThrow($exception);

        $list = $repository->all();

        self::assertCount(9, $list);
        self::assertSame(DataOrigin::Stale, $list->origin());
        self::assertSame($before, $this->store->all());
    }

    /**
     * @return iterable<string, array{?TransportException, int}>
     */
    public static function outages(): iterable
    {
        yield 'таймаут' => [new TimeoutException('таймаут', 'GET', 'https://www.gdeslon.ru/', 28), 0];
        yield 'отказ' => [new TransportException('отказ', 'GET', 'https://www.gdeslon.ru/', 7), 0];
        yield 'HTTP 503' => [null, 503];
    }

    public function testForbiddenIsNotHiddenByCache(): void
    {
        $this->transport->willReturn(200, self::fixture())->willReturn(403, 'нет');
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $this->clock->advance(self::TTL * 10);

        $this->expectException(AuthenticationException::class);

        $repository->all();
    }

    public function testBrokenDocumentServesStaleCopy(): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $before = $this->store->all();
        $this->clock->advance(self::TTL);
        $this->transport->willReturn(200, '<shops><shop>', ['ETag' => ['W/"new"']]);

        $list = $repository->all();
        self::assertCount(9, $list);
        self::assertSame(DataOrigin::Stale, $list->origin());
        self::assertSame($before, $this->store->all());
    }

    public function testAllRecordsBrokenServesStaleCopy(): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $before = $this->store->all();
        $this->clock->advance(self::TTL);
        $this->transport->willReturn(200, str_replace('<url>', '<site>', str_replace('</url>', '</site>', self::fixture())), ['ETag' => ['W/"new"']]);

        $list = $repository->all();
        self::assertCount(9, $list);
        self::assertSame(DataOrigin::Stale, $list->origin());
        self::assertSame($before, $this->store->all());
    }

    public function testResponseWithSkippedRecordsIsNotCached(): void
    {
        $partial = str_replace('rate_type="percent"', 'rate_type="percentage"', str_replace('rate_type="fixed"', 'rate_type="percentage"', self::fixture()));
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $before = $this->store->all();
        $this->clock->advance(self::TTL);
        $this->transport->willReturn(200, $partial, ['ETag' => ['W/"new"']]);

        $list = $repository->all();

        self::assertCount(2, $list);
        self::assertCount(7, $list->skipped());
        self::assertSame(DataOrigin::Fetched, $list->origin());
        self::assertSame($before, $this->store->all(), 'рабочая копия не вытесняется ответом с пропусками');

        $empty = new InMemoryCacheStore();
        $transport = (new FakeHttpTransport())->willReturn(200, $partial);
        (new HttpMerchantRepository(new ApiClient($transport), self::TOKEN, new DocumentCache($empty, $this->clock, self::TTL)))->all();
        self::assertSame([], $empty->all(), 'урезанный список не кэшируется и без копии');
    }

    public function testRejectedTokenIsNotHiddenByStaleCopy(): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $before = $this->store->all();
        $this->clock->advance(self::TTL);
        $this->transport->willReturn(200, Fixtures::read('merchants/shops-public.xml'), ['ETag' => ['W/"new"']]);

        try {
            $repository->all();
            self::fail('Ожидалось исключение');
        } catch (AuthenticationException) {
        }

        self::assertSame($before, $this->store->all());
    }

    public function testFailedCacheWriteDoesNotBreakLoading(): void
    {
        $this->store->failWrites();
        $this->transport->willReturn(200, self::fixture());

        self::assertCount(9, $this->repository(self::TOKEN)->all());
    }

    public function testPublicModeUsesTokenlessCacheKey(): void
    {
        $this->transport->willReturn(200, self::publicFixture(), ['ETag' => [self::ETAG]]);

        self::assertCount(2, $this->repository(self::TOKEN)->all(public: true));

        self::assertSame([DocumentCache::key(HttpMerchantRepository::DEFAULT_URL . '#' . hash('sha256', ''))], array_keys($this->store->all()));
        foreach ($this->store->all() as $storedKey => $value) {
            self::assertStringNotContainsString(self::TOKEN, $storedKey);
            self::assertStringNotContainsString(self::TOKEN, $value);
        }
    }

    public function testPublicCatalogIsSharedAcrossTokens(): void
    {
        $this->transport->willReturn(200, self::publicFixture(), ['ETag' => [self::ETAG]]);

        self::assertCount(2, $this->repository('token-a')->all(public: true));
        self::assertCount(2, $this->repository('token-b')->all(public: true));
        self::assertCount(2, $this->repository(null)->all(public: true));

        self::assertCount(1, $this->transport->requests());
        self::assertCount(1, $this->store->all());
    }

    public function testPublicModeWithTokenServesCachedPublicCopy(): void
    {
        $this->transport->willReturn(200, self::publicFixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all(public: true);
        $this->clock->advance(self::TTL * 10);
        $this->transport->willReturn(503, 'busy');

        $list = $repository->all(public: true);

        self::assertCount(2, $list);
        self::assertCount(2, $this->transport->requests());
        self::assertSame(HttpMerchantRepository::DEFAULT_URL, $this->transport->lastRequest()->uri());
    }

    public function testWithoutTokenDefaultModeIgnoresCachedPublicCopy(): void
    {
        $this->transport->willReturn(200, self::publicFixture(), ['ETag' => [self::ETAG]]);
        $this->repository(null)->all(public: true);
        $before = $this->store->all();

        try {
            $this->repository(null)->all();
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('merchants(public: true)', $e->getMessage());
        }

        self::assertCount(1, $this->transport->requests());
        self::assertSame($before, $this->store->all());

        $store = new class () implements CacheStore {
            public int $calls = 0;

            public function get(string $key): ?string
            {
                $this->calls++;

                return null;
            }

            public function set(string $key, string $value): bool
            {
                $this->calls++;

                return true;
            }
        };
        try {
            (new HttpMerchantRepository(new ApiClient($this->transport), null, new DocumentCache($store, $this->clock, self::TTL)))->all();
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException) {
        }
        self::assertSame(0, $store->calls);
    }

    public function testPublicModeRevalidatesWithEtag(): void
    {
        $this->transport->willReturn(200, self::publicFixture(), ['ETag' => [self::ETAG]])->willReturn(304, '', ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all(public: true);
        $this->clock->advance(self::TTL);

        self::assertCount(2, $repository->all(public: true));

        $request = $this->transport->lastRequest();
        self::assertSame(self::ETAG, $request->header('If-None-Match'));
        self::assertSame(HttpMerchantRepository::DEFAULT_URL, $request->uri());
        self::assertCount(2, $this->transport->requests());
    }

    public function testRefreshRevalidatesFreshEntryWithEtag(): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]])->willReturn(304, '', ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $this->clock->advance(10);

        $list = $repository->all(refresh: true);

        self::assertCount(9, $list);
        self::assertSame(DataOrigin::Revalidated, $list->origin());
        $request = $this->transport->lastRequest();
        self::assertSame(self::ETAG, $request->header('If-None-Match'));
        self::assertNull($request->header('If-Modified-Since'));
        self::assertSame('https://www.gdeslon.ru/api/users/shops.xml?api_token=' . self::TOKEN, $request->uri());
        self::assertSame($this->clock->now()->getTimestamp(), $this->stored()?->storedAt()->getTimestamp());

        $reordered = self::shops(['117999', '105263']);
        $this->transport->willReturn(200, $reordered, ['ETag' => ['W/"other"']]);
        $replaced = $repository->all(refresh: true);
        self::assertCount(2, $replaced);
        self::assertSame(DataOrigin::Fetched, $replaced->origin());
        self::assertSame($reordered, $this->stored()?->body());
        self::assertSame(DataOrigin::Cached, $repository->all()->origin());
        self::assertCount(3, $this->transport->requests());
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param class-string<GdeSlonException>                 $expected
     */
    #[DataProvider('refreshFailures')]
    public function testRefreshFailureThrowsAndKeepsCopy(\Closure $response, string $expected): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $before = $this->store->all();
        $response($this->transport);

        try {
            $repository->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (GdeSlonException $e) {
            self::assertSame($expected, $e::class);
        }

        self::assertSame($before, $this->store->all());
        self::assertCount(2, $this->transport->requests());
        self::assertSame(DataOrigin::Cached, $repository->all()->origin());
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, class-string<GdeSlonException>}>
     */
    public static function refreshFailures(): iterable
    {
        yield 'таймаут' => [static fn (FakeHttpTransport $t) => $t->willThrow(new TimeoutException('таймаут', 'GET', 'https://www.gdeslon.ru/', 28)), TimeoutException::class];
        yield 'отказ' => [static fn (FakeHttpTransport $t) => $t->willFail(7), TransportException::class];
        yield 'HTTP 500' => [static fn (FakeHttpTransport $t) => $t->willReturn(500, 'busy'), HttpException::class];
        yield 'HTTP 503' => [static fn (FakeHttpTransport $t) => $t->willReturn(503, 'busy'), HttpException::class];
        yield 'HTTP 404' => [static fn (FakeHttpTransport $t) => $t->willReturn(404, 'нет'), HttpException::class];
        yield 'HTTP 403' => [static fn (FakeHttpTransport $t) => $t->willReturn(403, 'нет'), AuthenticationException::class];
        yield 'HTTP 401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, 'нет'), AuthenticationException::class];
        yield 'битый XML' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, '<shops><shop>', ['ETag' => ['W/"new"']]), UnexpectedResponseException::class];
        yield 'токен не принят' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, Fixtures::read('merchants/shops-public.xml'), ['ETag' => ['W/"new"']]),
            AuthenticationException::class,
        ];
    }

    public function testRefreshWithSkippedRecordsReturnsFetchedListAndKeepsCopy(): void
    {
        $partial = str_replace('rate_type="percent"', 'rate_type="percentage"', str_replace('rate_type="fixed"', 'rate_type="percentage"', self::fixture()));
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]])->willReturn(200, $partial, ['ETag' => ['W/"new"']]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $before = $this->store->all();

        $list = $repository->all(refresh: true);

        self::assertCount(2, $list);
        self::assertCount(7, $list->skipped());
        self::assertSame(DataOrigin::Fetched, $list->origin());
        self::assertSame($before, $this->store->all());
    }

    public function testRefreshInPublicModeUsesTokenlessEntry(): void
    {
        $this->transport->willReturn(200, self::publicFixture(), ['ETag' => [self::ETAG]])->willReturn(304, '', ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all(public: true);

        $list = $repository->all(public: true, refresh: true);

        self::assertCount(2, $list);
        self::assertSame(DataOrigin::Revalidated, $list->origin());
        $request = $this->transport->lastRequest();
        self::assertSame(HttpMerchantRepository::DEFAULT_URL, $request->uri());
        self::assertSame(self::ETAG, $request->header('If-None-Match'));
        self::assertSame([DocumentCache::key(HttpMerchantRepository::DEFAULT_URL . '#' . hash('sha256', ''))], array_keys($this->store->all()));

        $this->transport->willReturn(304, '');
        self::assertSame(DataOrigin::Revalidated, $this->repository(null)->all(public: true, refresh: true)->origin(), 'публичная копия общая и без токена');
        self::assertCount(1, $this->store->all());
    }

    public function testRefreshWithoutTokenFailsBeforeRequestAndStore(): void
    {
        $store = new class () implements CacheStore {
            public int $calls = 0;

            public function get(string $key): ?string
            {
                $this->calls++;

                return null;
            }

            public function set(string $key, string $value): bool
            {
                $this->calls++;

                return true;
            }
        };

        try {
            (new HttpMerchantRepository(new ApiClient($this->transport), null, new DocumentCache($store, $this->clock, self::TTL)))->all(refresh: true);
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('merchants(public: true)', $e->getMessage());
        }

        self::assertSame(0, $store->calls);
        self::assertSame([], $this->transport->requests());
    }

    public function testRefreshTokensDoNotShareCache(): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]])->willReturn(200, self::shops(['105263']), ['ETag' => ['W/"b"']]);
        $this->repository('token-a')->all();
        $first = $this->store->all();

        $list = $this->repository('token-b')->all(refresh: true);

        self::assertCount(1, $list);
        self::assertSame(DataOrigin::Fetched, $list->origin());
        self::assertNull($this->transport->lastRequest()->header('If-None-Match'), 'копии token-a нет у token-b');
        self::assertCount(2, $this->store->all());
        foreach ($first as $key => $value) {
            self::assertSame($value, $this->store->all()[$key]);
        }
        self::assertSame(DataOrigin::Cached, $this->repository('token-a')->all()->origin());
    }

    private function repository(?string $token): HttpMerchantRepository
    {
        return new HttpMerchantRepository(new ApiClient($this->transport), $token, new DocumentCache($this->store, $this->clock, self::TTL));
    }

    /**
     * @phpstan-impure
     */
    private function stored(): ?CachedDocument
    {
        $value = $this->store->all() === [] ? null : array_values($this->store->all())[0];

        return $value === null ? null : CachedDocument::fromString($value);
    }

    private static function fixture(): string
    {
        return Fixtures::read('merchants/shops.xml');
    }

    private static function publicFixture(): string
    {
        return Fixtures::read('merchants/shops-public.xml');
    }

    /**
     * @param list<string> $ids
     */
    private static function shops(array $ids): string
    {
        preg_match_all('~  <shop>.*?</shop>\n~s', self::fixture(), $matches);
        $blocks = [];
        foreach ($matches[0] as $block) {
            preg_match('~<id><!\[CDATA\[(\d+)\]\]></id>~', $block, $id);
            $blocks[$id[1] ?? ''] = $block;
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<shops>\n" . implode('', array_map(static fn (string $id): string => $blocks[$id], $ids)) . "</shops>\n";
    }
}
