<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;

final class FileCacheStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/gdeslon-cache-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    public function testStoresBinaryValues(): void
    {
        $store = new FileCacheStore($this->root . '/cache');
        $value = "Всё для шитья\0" . str_repeat('ж', 100000);

        self::assertTrue($store->set('gdeslon_abc', $value));
        self::assertSame($value, $store->get('gdeslon_abc'));
        self::assertNull($store->get('gdeslon_missing'));
    }

    public function testCreatesPrivateDirectoryOnFirstWrite(): void
    {
        $directory = $this->root . '/nested/cache';
        $store = new FileCacheStore($directory);
        self::assertDirectoryDoesNotExist($directory, 'конструктор не трогает файловую систему');

        $store->set('gdeslon_abc', 'x');

        self::assertSame(0700, fileperms($directory) & 0777);
        self::assertSame(0600, fileperms($directory . '/gdeslon_abc') & 0777);
    }

    public function testOverwriteLeavesNoTemporaryFiles(): void
    {
        $store = new FileCacheStore($this->root);

        $store->set('gdeslon_abc', 'первое');
        $store->set('gdeslon_abc', 'второе');

        self::assertSame('второе', $store->get('gdeslon_abc'));
        self::assertSame(['gdeslon_abc'], array_values(array_diff((array) scandir($this->root), ['.', '..'])));
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsUnsafeKeys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileCacheStore($this->root))->set($key, 'x');
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsUnsafeKeysOnRead(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileCacheStore($this->root))->get($key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'выход из каталога' => ['../x'];
        yield 'подкаталог' => ['a/b'];
        yield 'пустой' => [''];
        yield 'длинный' => [str_repeat('a', 65)];
        yield 'двоеточие' => ['a:b'];
        yield 'перевод строки' => ["key\n"];
        yield 'начинается с точки' => ['..'];
    }

    public function testUnusableDirectoryFailsQuietly(): void
    {
        mkdir($this->root);
        file_put_contents($this->root . '/file', 'не каталог');
        $store = new FileCacheStore($this->root . '/file');

        self::assertFalse($store->set('gdeslon_abc', 'x'));
        self::assertNull($store->get('gdeslon_abc'));
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
