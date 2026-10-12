<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Picture;
use Webreboot\GdeSlon\Domain\Catalog\PictureSource;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class PictureTest extends TestCase
{
    public function testKeepsUrlAndSource(): void
    {
        $picture = new Picture('https://imgng.gdeslon.ru/p/big.jpg', PictureSource::Picture);

        self::assertSame('https://imgng.gdeslon.ru/p/big.jpg', $picture->url());
        self::assertSame(PictureSource::Picture, $picture->source());
    }

    public function testKeepsHttpAndCyrillicUrlAsIs(): void
    {
        self::assertSame('http://cdn.example.ru/a.png', (new Picture('http://cdn.example.ru/a.png', PictureSource::Original))->url());
        self::assertSame('https://пример.рф/картинка.jpg', (new Picture('https://пример.рф/картинка.jpg', PictureSource::Original))->url());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'пусто' => [''];
        yield 'пробел в начале' => [' https://x/a.jpg'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'относительный' => ['/img/a.jpg'];
        yield 'ftp' => ['ftp://cdn.example.ru/a.png'];
        yield 'data' => ['data:image/png;base64,AAAA'];
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsNonHttpUrl(string $url): void
    {
        try {
            new Picture($url, PictureSource::Thumbnail);
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertInstanceOf(GdeSlonException::class, $e);
            self::assertSame('Адрес картинки (thumbnail) — ожидался адрес http(s)://', $e->getMessage());
        }
    }
}
