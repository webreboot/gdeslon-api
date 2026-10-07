<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;
use Webreboot\GdeSlon\Tests\Support\InMemoryCacheStore;

final class DocumentCacheTest extends TestCase
{
    private const URL = 'https://api.gdeslon.ru/gdeslon-categories.json';

    public function testKeyIsSafeForEveryStore(): void
    {
        $key = DocumentCache::key(self::URL);

        self::assertMatchesRegularExpression('/^gdeslon_[0-9a-f]{40}$/', $key);
        self::assertLessThanOrEqual(64, strlen($key));
        self::assertNotSame($key, DocumentCache::key('https://mirror.example/categories.json'));
    }

    public function testSaveAndFind(): void
    {
        $store = new InMemoryCacheStore();
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $cache = new DocumentCache($store, $clock, 3600);

        $cache->save(self::URL, new HttpResponse(200, '{"1":{}}', [
            'ETag' => ['"6724af75-26edb"', '"второй"'],
            'Last-Modified' => ['Fri, 01 Nov 2024 10:37:41 GMT'],
        ]));

        $document = $cache->find(self::URL);
        self::assertNotNull($document);
        self::assertSame('{"1":{}}', $document->body());
        self::assertSame('"6724af75-26edb"', $document->etag());
        self::assertSame('Fri, 01 Nov 2024 10:37:41 GMT', $document->lastModified());
        self::assertSame($clock->now()->getTimestamp(), $document->storedAt()->getTimestamp());
        self::assertArrayHasKey(DocumentCache::key(self::URL), $store->all());
    }

    public function testSaveWithoutValidatorsAndMissingEntry(): void
    {
        $cache = new DocumentCache(new InMemoryCacheStore(), FrozenClock::at('2026-10-07'), 3600);

        self::assertNull($cache->find(self::URL));

        $cache->save(self::URL, new HttpResponse(200, '{}', []));
        $document = $cache->find(self::URL);
        self::assertNotNull($document);
        self::assertNull($document->etag());
        self::assertNull($document->lastModified());
    }

    public function testCorruptedEntryIsMiss(): void
    {
        $store = new InMemoryCacheStore();
        $store->set(DocumentCache::key(self::URL), 'мусор');

        self::assertNull((new DocumentCache($store, FrozenClock::at('2026-10-07'), 3600))->find(self::URL));
    }

    public function testFreshness(): void
    {
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $document = new CachedDocument('{}', null, null, $clock->now());
        $cache = new DocumentCache(new InMemoryCacheStore(), $clock, 3600);

        self::assertTrue($cache->isFresh($document));
        $clock->advance(3599);
        self::assertTrue($cache->isFresh($document));
        $clock->advance(1);
        self::assertFalse($cache->isFresh($document), 'ровно ttl — уже устарела');

        $clock->advance(-7200);
        self::assertFalse($cache->isFresh($document), 'часы ушли назад — не доверяем записи');

        self::assertFalse((new DocumentCache(new InMemoryCacheStore(), FrozenClock::at('2026-10-07 12:00:00'), 0))->isFresh(
            new CachedDocument('{}', null, null, new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'))),
        ), 'ttl 0 — всегда проверять');
    }

    public function testTouchUpdatesOnlyStoredAt(): void
    {
        $store = new InMemoryCacheStore();
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $cache = new DocumentCache($store, $clock, 3600);
        $cache->save(self::URL, new HttpResponse(200, '{"a":1}', ['ETag' => ['"e"']]));
        $document = $cache->find(self::URL);
        self::assertNotNull($document);

        $clock->advance(7200);
        $cache->touch(self::URL, $document);

        $touched = $cache->find(self::URL);
        self::assertNotNull($touched);
        self::assertSame($clock->now()->getTimestamp(), $touched->storedAt()->getTimestamp());
        self::assertSame(['{"a":1}', '"e"'], [$touched->body(), $touched->etag()]);
    }

    public function testRejectsNegativeTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DocumentCache(new InMemoryCacheStore(), FrozenClock::at('2026-10-07'), -1);
    }

    public function testFailedWriteIsReported(): void
    {
        $cache = new DocumentCache((new InMemoryCacheStore())->failWrites(), FrozenClock::at('2026-10-07'), 3600);

        self::assertFalse($cache->save(self::URL, new HttpResponse(200, '{}', [])));
    }
}
