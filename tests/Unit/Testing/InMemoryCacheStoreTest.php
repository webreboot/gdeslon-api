<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Testing\InMemoryCacheStore;

final class InMemoryCacheStoreTest extends TestCase
{
    public function testMissingKeyIsNull(): void
    {
        self::assertNull((new InMemoryCacheStore())->get('нет'));
    }

    public function testValueIsStoredByteForByte(): void
    {
        $store = new InMemoryCacheStore();
        $value = "\xEF\xBB\xBF{\"a\":1}\r\n\0";

        self::assertTrue($store->set('k', $value));
        self::assertSame($value, $store->get('k'));
        self::assertTrue($store->set('empty', ''));
        self::assertSame('', $store->get('empty'));
    }

    public function testSetOverwrites(): void
    {
        $store = new InMemoryCacheStore();
        $store->set('k', 'старое');
        $store->set('k', 'новое');

        self::assertSame('новое', $store->get('k'));
        self::assertSame(['k' => 'новое'], $store->all());
    }

    public function testFailWritesRejectsWritesAndKeepsValues(): void
    {
        $store = new InMemoryCacheStore();
        $store->set('k', 'было');

        self::assertSame($store, $store->failWrites());
        self::assertFalse($store->set('k', 'стало'));
        self::assertFalse($store->set('other', 'x'));
        self::assertSame('было', $store->get('k'));
        self::assertSame(['k' => 'было'], $store->all());
    }

    public function testAllIsSnapshot(): void
    {
        $store = new InMemoryCacheStore();
        $store->set('a', '1');
        $snapshot = $store->all();
        $store->set('b', '2');

        self::assertSame(['a' => '1'], $snapshot);
        self::assertSame(['a' => '1', 'b' => '2'], $store->all());
    }
}
