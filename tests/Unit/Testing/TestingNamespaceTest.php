<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryRepository;
use Webreboot\GdeSlon\Domain\Catalog\MerchantRepository;
use Webreboot\GdeSlon\Domain\Catalog\ProductCatalog;
use Webreboot\GdeSlon\Domain\Catalog\ProductFeed;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaims;
use Webreboot\GdeSlon\Domain\Promo\CouponFeed;
use Webreboot\GdeSlon\Domain\Sales\OrderRepository;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Infrastructure\Cache\CacheStore;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;

final class TestingNamespaceTest extends TestCase
{
    private const PORTS = [
        'FakeHttpTransport' => HttpTransport::class,
        'FrozenClock' => Clock::class,
        'InMemoryCacheStore' => CacheStore::class,
        'FakeProductCatalog' => ProductCatalog::class,
        'FakeProductFeed' => ProductFeed::class,
        'FakeCategoryRepository' => CategoryRepository::class,
        'FakeMerchantRepository' => MerchantRepository::class,
        'FakeOrderRepository' => OrderRepository::class,
        'FakeCouponFeed' => CouponFeed::class,
        'FakeLostOrderClaims' => LostOrderClaims::class,
    ];

    public function testTestingIsAutoloadedAndShipped(): void
    {
        $composer = json_decode((string) file_get_contents(self::root() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $autoload = $composer['autoload'] ?? null;
        self::assertIsArray($autoload);
        self::assertSame(['Webreboot\\GdeSlon\\' => 'src/'], $autoload['psr-4'] ?? null);

        $files = array_map(static fn (string $path): string => basename($path, '.php'), glob(self::root() . '/src/Testing/*.php') ?: []);
        sort($files);
        $expected = array_keys(self::PORTS);
        sort($expected);
        self::assertSame($expected, $files);
        foreach (array_keys(self::PORTS) as $name) {
            self::assertTrue(class_exists('Webreboot\\GdeSlon\\Testing\\' . $name), $name);
        }

        foreach (file(self::root() . '/.gitattributes', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $parts = preg_split('~\s+~', trim($line)) ?: [];
            if (!in_array('export-ignore', $parts, true)) {
                continue;
            }
            $pattern = rtrim(ltrim($parts[0], '/'), '/*');
            self::assertFalse(
                $pattern === 'src' || str_starts_with('src/Testing', $pattern . '/') || str_starts_with($pattern, 'src/Testing'),
                'src/Testing исключён из архива правилом ' . $line,
            );
        }
    }

    public function testSrcDoesNotDependOnPhpunit(): void
    {
        foreach (self::phpFiles(self::root() . '/src') as $path => $code) {
            self::assertStringNotContainsStringIgnoringCase('phpunit', $code, $path);
        }
    }

    public function testProductionCodeDoesNotUseTesting(): void
    {
        $files = self::phpFiles(self::root() . '/src');
        $files[self::root() . '/bin/gdeslon'] = (string) file_get_contents(self::root() . '/bin/gdeslon');
        foreach ($files as $path => $code) {
            if (str_contains(str_replace('\\', '/', $path), '/src/Testing/')) {
                continue;
            }
            self::assertStringNotContainsString('GdeSlon\\Testing', $code, $path);
        }
    }

    public function testEveryTestingClassIsFinalPublicAndImplementsPort(): void
    {
        foreach (self::PORTS as $name => $port) {
            $class = new \ReflectionClass('Webreboot\\GdeSlon\\Testing\\' . $name);
            self::assertTrue($class->isFinal(), $name);
            self::assertTrue($class->implementsInterface($port), $name . ' реализует ' . $port);
            self::assertStringNotContainsString('@internal', (string) $class->getDocComment(), $name);
            self::assertSame([], $class->getProperties(\ReflectionProperty::IS_PUBLIC), $name . ': публичные свойства');
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                self::assertStringNotContainsString('@internal', (string) $method->getDocComment(), $name . '::' . $method->name);
            }
        }
    }

    public function testSupportHasNoCopiesOfPublicFakes(): void
    {
        $tests = self::root() . '/tests';
        foreach (array_keys(self::PORTS) as $name) {
            self::assertFileDoesNotExist($tests . '/Support/' . $name . '.php');
        }

        $pattern = '~Tests\\\\Support\\\\(?:' . implode('|', array_keys(self::PORTS)) . ')\b~';
        foreach (self::phpFiles($tests) as $path => $code) {
            self::assertDoesNotMatchRegularExpression($pattern, $code, $path);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
