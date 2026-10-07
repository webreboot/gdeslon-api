<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Cache\Psr16CacheStore;
use Webreboot\GdeSlon\Tests\Support\ArrayPsr16Cache;

final class Psr16CacheStoreTest extends TestCase
{
    public function testDelegatesToPsr16Cache(): void
    {
        $psr = new ArrayPsr16Cache();
        $store = new Psr16CacheStore($psr);

        self::assertTrue($store->set('gdeslon_abc.1', 'Всё для шитья'));
        self::assertSame(['gdeslon_abc.1' => 'Всё для шитья'], $psr->values());
        self::assertSame('Всё для шитья', $store->get('gdeslon_abc.1'));
        self::assertNull($store->get('gdeslon_missing'));
    }

    public function testNonStringValueIsMiss(): void
    {
        $psr = new ArrayPsr16Cache();
        $psr->set('gdeslon_abc', ['не строка']);

        self::assertNull((new Psr16CacheStore($psr))->get('gdeslon_abc'));
    }

    public function testCacheErrorsDoNotEscape(): void
    {
        $store = new Psr16CacheStore((new ArrayPsr16Cache())->failing());

        self::assertNull($store->get('gdeslon_abc'));
        self::assertFalse($store->set('gdeslon_abc', 'x'));
    }

    public function testBackendErrorsOutsidePsr16DoNotEscape(): void
    {
        // например RedisException у кэша фреймворка при недоступном Redis
        $store = new Psr16CacheStore((new ArrayPsr16Cache())->failingWith(new \RuntimeException('Redis недоступен')));

        self::assertNull($store->get('gdeslon_abc'));
        self::assertFalse($store->set('gdeslon_abc', 'x'));
    }
}
