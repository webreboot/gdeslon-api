<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\OfferParam;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OfferParamTest extends TestCase
{
    public function testValues(): void
    {
        $param = new OfferParam('Размер', '42', 'RU');

        self::assertSame('Размер', $param->name());
        self::assertSame('42', $param->value());
        self::assertSame('RU', $param->unit());
    }

    public function testNameValueAndUnitAreTrimmedIncludingNbsp(): void
    {
        $param = new OfferParam(" \u{00A0}Цвет\t", "\u{00A0}Серый \n", ' RU ');

        self::assertSame('Цвет', $param->name());
        self::assertSame('Серый', $param->value());
        self::assertSame('RU', $param->unit());
    }

    public function testUnitIsOptionalAndBlankUnitIsNull(): void
    {
        self::assertNull((new OfferParam('Цвет', 'Серый'))->unit());
        self::assertNull((new OfferParam('Цвет', 'Серый', ''))->unit());
        self::assertNull((new OfferParam('Цвет', 'Серый', "\u{00A0}"))->unit());
    }

    public function testValueKeptAsIs(): void
    {
        self::assertSame('38-40RU 36-38RU 40-42RU', (new OfferParam('Размер', '38-40RU 36-38RU 40-42RU'))->value());
        self::assertSame('Демисезон', (new OfferParam('Сезон', 'Демисезон'))->value());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blankParts(): iterable
    {
        yield 'пустое имя' => ['', 'Серый'];
        yield 'имя из пробелов' => ["  \u{00A0}", 'Серый'];
        yield 'пустое значение' => ['Цвет', ''];
        yield 'значение из пробелов' => ['Цвет', " \n\u{00A0}"];
    }

    #[DataProvider('blankParts')]
    public function testBlankNameOrValueRejected(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OfferParam($name, $value);
    }
}
