<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Cli;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Cli\Output\Details;
use Webreboot\GdeSlon\Interface\Cli\Output\JsonOutput;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Cli\Output\TextWidth;

final class OutputTest extends TestCase
{
    public function testWidth(): void
    {
        $cases = ['' => 0, 'abc' => 3, 'Парки' => 5, "е\u{0308}ж" => 2, '中文' => 4, '🎁' => 2, "\xffab" => 3];
        foreach ($cases as $text => $width) {
            self::assertSame($width, TextWidth::width((string) $text), (string) $text);
        }
    }

    public function testTruncate(): void
    {
        self::assertSame('Женская…', TextWidth::truncate('Женская одежда', 8));
        self::assertSame(8, TextWidth::width(TextWidth::truncate('Женская одежда', 8)));
        self::assertSame('Женская', TextWidth::truncate('Женская', 7));
        self::assertSame('…', TextWidth::truncate('Женская', 1));
        self::assertSame('中…', TextWidth::truncate('中文字', 4), 'широкий символ не разрезается');
    }

    public function testSanitize(): void
    {
        self::assertSame('A [31mB  C 🎁 ж', TextWidth::sanitize("A\e[31mB\r\nC\xC2\x9B🎁\tж"));
    }

    public function testTable(): void
    {
        $table = Table::render(['ID', 'Название', 'Цена'], [
            ['1', 'Платье', '100 RUR'],
            ['22', "Очень длинное название\nтовара", null],
        ], [1 => 12]);

        self::assertSame(
            "ID  Название      Цена\n"
            . "--  ------------  -------\n"
            . "1   Платье        100 RUR\n"
            . "22  Очень длинн…  —\n",
            $table,
        );
    }

    public function testDetails(): void
    {
        self::assertSame("ID:       5796\nМагазин:  Пример\nОписание: —\n", Details::render([['ID', '5796'], ['Магазин', 'Пример'], ['Описание', null]]));
    }

    public function testJson(): void
    {
        self::assertSame("{\n    \"name\": \"Платье\",\n    \"url\": \"https://x/y\",\n    \"ctl\": \"\\u001b\"\n}\n", JsonOutput::encode(['name' => 'Платье', 'url' => 'https://x/y', 'ctl' => "\e"]));

        self::assertSame("{\n    \"d\": \"a\\u0085b\\u009b\"\n}\n", JsonOutput::encode(['d' => "a\u{0085}b\u{009B}"]), 'C1 экранированы: Console их не заменит, данные не меняются');

        $this->expectException(\JsonException::class);
        JsonOutput::encode(['bad' => "\xff"]);
    }
}
