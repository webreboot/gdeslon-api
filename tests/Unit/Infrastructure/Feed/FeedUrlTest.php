<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Feed;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;

final class FeedUrlTest extends TestCase
{
    private const URL = 'https://export.gdeslon.ru/uploads/exports/Zk3secretSecretSecret0123456789abcdefABCDEF.zip';

    private const SECRET = 'Zk3secretSecretSecret0123456789abcdefABCDEF';

    public function testValueAndMasked(): void
    {
        $url = FeedUrl::fromString(self::URL);

        self::assertSame(self::URL, $url->value());
        self::assertSame('https://export.gdeslon.ru/uploads/exports/***', $url->masked());
        self::assertSame('https://export.gdeslon.ru/uploads/exports/***', (string) $url);
    }

    public function testSecretsContainUrlAndLastSegment(): void
    {
        $secrets = FeedUrl::fromString(self::URL)->secrets();

        self::assertContains(self::URL, $secrets);
        self::assertContains(self::SECRET . '.zip', $secrets);
        self::assertContains(self::SECRET, $secrets);
    }

    public function testQueryFragmentAndUserinfoAreMasked(): void
    {
        $url = FeedUrl::fromString('https://user:pass@export.gdeslon.ru/e/secretname.zip?token=abc#frag');

        self::assertSame('https://export.gdeslon.ru/e/***', $url->masked());
        foreach (['user', 'pass', 'secretname', 'abc', 'frag'] as $secret) {
            self::assertStringNotContainsString($secret, $url->masked());
        }
    }

    public function testRootPathIsMasked(): void
    {
        self::assertSame('https://export.gdeslon.ru/***', FeedUrl::fromString('https://export.gdeslon.ru/abc.zip')->masked());
        self::assertSame('https://export.gdeslon.ru:8443/***', FeedUrl::fromString('https://export.gdeslon.ru:8443/abc.zip')->masked());
    }

    public function testHttpAllowedOnlyForLoopback(): void
    {
        self::assertSame('http://127.0.0.1:8080/feed/***', FeedUrl::fromString('http://127.0.0.1:8080/feed/abc.zip')->masked());
        self::assertSame('http://localhost/***', FeedUrl::fromString('http://localhost/abc.zip')->masked());
        self::assertSame('http://[::1]:81/***', FeedUrl::fromString('http://[::1]:81/abc.zip')->masked());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'http не loopback' => ['http://export.gdeslon.ru/uploads/exports/abc.zip'];
        yield 'ftp' => ['ftp://export.gdeslon.ru/abc.zip'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'без схемы' => ['export.gdeslon.ru/abc.zip'];
        yield 'пусто' => [''];
        yield 'пробел' => ['https://export.gdeslon.ru/a b.zip'];
        yield 'перевод строки' => ["https://export.gdeslon.ru/abc.zip\r\nX: y"];
        yield 'без пути' => ['https://export.gdeslon.ru'];
        yield 'http локальный адрес-ловушка' => ['http://127.0.0.1.evil.com/abc.zip'];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlRejectedWithoutEcho(string $url): void
    {
        try {
            FeedUrl::fromString($url);
            self::fail('ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('abc', $e->getMessage());
            self::assertStringNotContainsString('passwd', $e->getMessage());
        }
    }

    public function testDumpsDoNotRevealSecret(): void
    {
        $url = FeedUrl::fromString(self::URL);

        self::assertStringNotContainsString(self::SECRET, print_r($url, true));
        self::assertStringNotContainsString(self::SECRET, var_export($url, true));
        self::assertStringNotContainsString(self::SECRET, print_r((array) $url, true));
        self::assertStringNotContainsString(self::SECRET, print_r(get_object_vars($url), true));
        ob_start();
        var_dump($url);
        self::assertStringNotContainsString(self::SECRET, (string) ob_get_clean());
        self::assertStringNotContainsString(self::SECRET, json_encode($url, JSON_THROW_ON_ERROR));
        self::assertSame(self::URL, $url->value());
    }

    public function testCloneIsRefused(): void
    {
        $this->expectException(\Error::class);

        $url = FeedUrl::fromString(self::URL);
        $copy = clone $url;
        self::assertSame(self::URL, $copy->value());
    }

    public function testSerializationIsRefused(): void
    {
        try {
            serialize(FeedUrl::fromString(self::URL));
            self::fail('ожидалось исключение');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getTraceAsString());
        }
    }

    public function testLooseComparisonDistinguishesFeeds(): void
    {
        $a = FeedUrl::fromString(self::URL);
        $b = FeedUrl::fromString('https://export.gdeslon.ru/uploads/exports/other0123456789.zip');

        self::assertTrue($a == FeedUrl::fromString(self::URL));
        self::assertFalse($a == $b);
        self::assertFalse(in_array($b, [$a]));
        self::assertStringNotContainsString(self::SECRET, var_export($a, true));
    }

    public function testForgedUnserializeIsRefused(): void
    {
        $class = FeedUrl::class;
        $forged = sprintf('O:%d:"%s":1:{s:%d:"%s";s:3:"***";}', strlen($class), $class, strlen("\0" . $class . "\0masked"), "\0" . $class . "\0masked");

        $this->expectException(InvalidArgumentException::class);

        unserialize($forged);
    }

    public function testEquals(): void
    {
        self::assertTrue(FeedUrl::fromString(self::URL)->equals(FeedUrl::fromString(self::URL)));
        self::assertFalse(FeedUrl::fromString(self::URL)->equals(FeedUrl::fromString('https://export.gdeslon.ru/uploads/exports/other.zip')));
    }
}
