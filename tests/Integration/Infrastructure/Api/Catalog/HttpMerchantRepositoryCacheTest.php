<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpCategoryRepository;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpMerchantRepository;
use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;
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
        $repository->all();

        $this->clock->advance(self::TTL - 1);
        self::assertCount(9, $repository->all());
        self::assertCount(1, $this->transport->requests(), 'свежая запись — без запроса');

        $this->clock->advance(1);
        self::assertCount(9, $repository->all());
        $request = $this->transport->lastRequest();
        self::assertSame(self::ETAG, $request->header('If-None-Match'));
        self::assertNull($request->header('If-Modified-Since'));
        self::assertSame('https://www.gdeslon.ru/api/users/shops.xml?api_token=' . self::TOKEN, $request->uri());

        self::assertCount(9, $repository->all());
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

        self::assertCount(2, $repository->all());
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
    public function testStaleEntryIsServedOnOutage(?TimeoutException $exception, int $status): void
    {
        $this->transport->willReturn(200, self::fixture(), ['ETag' => [self::ETAG]]);
        $repository = $this->repository(self::TOKEN);
        $repository->all();
        $this->clock->advance(self::TTL * 10);
        $exception === null ? $this->transport->willReturn($status, 'busy') : $this->transport->willThrow($exception);

        self::assertCount(9, $repository->all());
    }

    /**
     * @return iterable<string, array{?TimeoutException, int}>
     */
    public static function outages(): iterable
    {
        yield 'таймаут' => [new TimeoutException('таймаут', 'GET', 'https://www.gdeslon.ru/', 28), 0];
        yield '503' => [null, 503];
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

        self::assertCount(9, $repository->all());
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

        self::assertCount(9, $repository->all());
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

    private function repository(?string $token): HttpMerchantRepository
    {
        return new HttpMerchantRepository(new ApiClient($this->transport), $token, new DocumentCache($this->store, $this->clock, self::TTL));
    }

    private function stored(): ?CachedDocument
    {
        $value = $this->store->all() === [] ? null : array_values($this->store->all())[0];

        return $value === null ? null : CachedDocument::fromString($value);
    }

    private static function fixture(): string
    {
        return Fixtures::read('merchants/shops.xml');
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
