<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Testing;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Shared\DataOrigin;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Testing\FakeCategoryRepository;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Testing\FrozenClock;
use Webreboot\GdeSlon\Testing\InMemoryCacheStore;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class ConsumerExampleTest extends TestCase
{
    public function testReadmeExample(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search.xml'));
        $gdeslon = GdeSlon::create(new Config(apiToken: 'test-token'), $transport);

        $page = $gdeslon->search('iphone');

        self::assertCount(7, $page);
        self::assertSame('iphone', $transport->lastRequest()->query()['q'] ?? null);
    }

    public function testFailuresThroughFake(): void
    {
        $cases = [
            AuthenticationException::class => (new FakeHttpTransport())->willReturn(403, 'This affiliate token does not exists', ['Content-Type' => ['text/html; charset=utf-8']]),
            UnexpectedResponseException::class => (new FakeHttpTransport())->willReturn(200, '<yml_catalog><offers><offer'),
            TimeoutException::class => (new FakeHttpTransport())->willFail(28, 'Operation timed out'),
            TransportException::class => (new FakeHttpTransport())->willFail(7),
        ];
        foreach ($cases as $expected => $transport) {
            try {
                GdeSlon::create(new Config(apiToken: 'test-token', searchFallbackLimit: null), $transport)->search('iphone');
                self::fail('Ожидалось ' . $expected);
            } catch (GdeSlonException $e) {
                self::assertInstanceOf($expected, $e);
                self::assertStringNotContainsString('test-token', $e->getMessage());
            }
        }

        $empty = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search-empty.xml'));
        self::assertTrue(GdeSlon::create(new Config(apiToken: 'test-token'), $empty)->search('iphone')->isEmpty());

        $unexpected = new FakeHttpTransport();
        try {
            GdeSlon::create(new Config(apiToken: 'test-token'), $unexpected)->search('iphone');
            self::fail('Ожидалось \LogicException');
        } catch (GdeSlonException $e) {
            self::fail('Пустая очередь фейка — не ошибка SDK: ' . $e::class);
        } catch (\LogicException $e) {
            self::assertStringNotContainsString('test-token', $e->getMessage());
        }
    }

    public function testCacheAndClockFakes(): void
    {
        $transport = (new FakeHttpTransport())
            ->willReturn(200, Fixtures::read('categories/categories.json'), ['ETag' => '"v1"'])
            ->willReturn(304, '', ['ETag' => '"v1"']);
        $cache = new InMemoryCacheStore();
        $clock = FrozenClock::at('2026-10-07 10:00:00');
        $gdeslon = GdeSlon::create(new Config(cacheTtl: 3600), $transport, $cache, $clock);

        self::assertSame(DataOrigin::Fetched, $gdeslon->categories()->origin());
        self::assertSame(DataOrigin::Cached, $gdeslon->categories()->origin());
        self::assertCount(1, $transport->requests());
        self::assertNotSame([], $cache->all());

        $clock->advance(3600);
        self::assertSame(DataOrigin::Revalidated, $gdeslon->categories()->origin());
        self::assertSame('"v1"', $transport->lastRequest()->header('If-None-Match'));

        $tree = new CategoryTree([]);
        $categories = new FakeCategoryRepository($tree);
        self::assertSame($tree, (new GdeSlon(new FakeHttpTransport(), categories: $categories))->categories());
        self::assertSame(1, $categories->calls());
    }
}
