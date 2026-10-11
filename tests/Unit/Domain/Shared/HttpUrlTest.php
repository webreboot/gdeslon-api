<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Shared\HttpUrl;

final class HttpUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'https' => ['https://imgng.gdeslon.ru/commodities/1/pictures/a/big.jpg'];
        yield 'http' => ['http://cdn.example.ru/a.png'];
        yield 'регистр схемы' => ['HTTPS://Example.RU/'];
        yield 'только хост' => ['https://x.ru'];
        yield 'запрос' => ['https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&goto=https%3A%2F%2Fx.ru'];
        yield 'кавычка в пути — проверяются только схема и хост' => ['https://x.ru/" onerror="a'];
    }

    #[DataProvider('valid')]
    public function testValid(string $url): void
    {
        self::assertTrue(HttpUrl::isValid($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'пусто' => [''];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:image/png;base64,AAAA'];
        yield 'ftp' => ['ftp://x.ru/a.png'];
        yield 'относительный' => ['/images/logo.png'];
        yield 'протокол-относительный' => ['//x.ru/a.png'];
        yield 'без схемы' => ['x.ru/a.png'];
        yield 'без хоста' => ['https:///a.png'];
        yield 'пробел до схемы' => [' https://x.ru/'];
    }

    #[DataProvider('invalid')]
    public function testInvalid(string $url): void
    {
        self::assertFalse(HttpUrl::isValid($url));
    }

    public function testOrNull(): void
    {
        self::assertSame('https://x.ru/a.png', HttpUrl::orNull('https://x.ru/a.png'));
        self::assertNull(HttpUrl::orNull(null));
        self::assertNull(HttpUrl::orNull('javascript:alert(1)'));
        self::assertNull(HttpUrl::orNull('/logo.png'));
        self::assertNull(HttpUrl::orNull(''));
    }
}
