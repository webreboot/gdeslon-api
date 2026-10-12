<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\PictureSource;

final class PictureSourceTest extends TestCase
{
    public function testCasesAreDeclaredInPriorityOrder(): void
    {
        self::assertSame([PictureSource::Original, PictureSource::Picture, PictureSource::Thumbnail], PictureSource::cases());
    }

    public function testBackingValuesMatchApiFieldNames(): void
    {
        self::assertSame('original_picture', PictureSource::Original->value);
        self::assertSame('picture', PictureSource::Picture->value);
        self::assertSame('thumbnail', PictureSource::Thumbnail->value);
        self::assertSame(PictureSource::Thumbnail, PictureSource::from('thumbnail'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownValues(): iterable
    {
        yield 'big' => ['big'];
        yield 'пусто' => [''];
        yield 'имя случая' => ['Picture'];
        yield 'короткое' => ['original'];
    }

    #[DataProvider('unknownValues')]
    public function testUnknownValueIsNotSource(string $value): void
    {
        self::assertNull(PictureSource::tryFrom($value));
    }

    /**
     * @return iterable<string, array{PictureSource, PictureSource, bool}>
     */
    public static function pairs(): iterable
    {
        foreach (PictureSource::cases() as $i => $a) {
            foreach (PictureSource::cases() as $j => $b) {
                yield $a->name . ' → ' . $b->name => [$a, $b, $i < $j];
            }
        }
    }

    #[DataProvider('pairs')]
    public function testIsPreferredTo(PictureSource $a, PictureSource $b, bool $expected): void
    {
        self::assertSame($expected, $a->isPreferredTo($b));
    }
}
