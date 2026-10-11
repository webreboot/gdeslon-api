<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Shared\AdMarking;

final class AdMarkingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function realForms(): iterable
    {
        yield 'оффер' => ['Реклама. Рекламодатель АМ КРАСНОГОРСК ИНН 5032280208 , https://amwine.ru/ erid 2SDnjcZqvUG', '2SDnjcZqvUG'];
        yield 'оффер с коротким erid' => ['Реклама. Рекламодатель ООО "ШОП ТВ" ИНН 7723823400 , https://www.leomax.ru/ erid Kra23x72C', 'Kra23x72C'];
        yield 'магазин' => ['Реклама. Рекламодатель ООО «ИНТЕРМОДЕ» ИНН 7709900734, https://superstep.ru/. erid Kra23dyAk', 'Kra23dyAk'];
        yield 'купон' => ['Реклама. Рекламодатель ООО "Пример 1" ИНН 7700000001. erid 2SDnjTEST001', '2SDnjTEST001'];
        yield 'с двоеточием' => ['Реклама. ООО «Слон». erid: 2SDnjTEST002', '2SDnjTEST002'];
        yield 'заглавными' => ['Реклама. ERID 2SDnjTEST003', '2SDnjTEST003'];
        yield 'точка после значения' => ['Реклама. erid 2SDnjTEST004.', '2SDnjTEST004'];
        yield 'перевод строки' => ["Реклама.\nerid\t2SDnjTEST005\n", '2SDnjTEST005'];
    }

    #[DataProvider('realForms')]
    public function testEridFromRealForms(string $marking, string $erid): void
    {
        self::assertSame($erid, AdMarking::erid($marking));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function withoutErid(): iterable
    {
        yield 'null' => [null];
        yield 'пусто' => [''];
        yield 'AliExpress' => ['Реклама. Рекламодатель http://aliexpress.com/.'];
        yield 'ИНН и сайт без erid' => ['Реклама. Рекламодатель ООО Новые Туристические Технологии ИНН 7724929270 , https://www.tutu.ru'];
        yield 'слово без значения' => ['Реклама. erid'];
        yield 'слишком короткое значение' => ['Реклама. erid abc'];
        yield 'внутри слова' => ['veridian 2SDnjTEST001'];
        yield 'кириллица в значении' => ['erid Кra23dyAk'];
        yield 'сайт с erid в имени' => ['Реклама. https://erid.ru/ 2SDnjTEST001'];
        yield 'значение длиннее 64' => ['erid ' . str_repeat('a', 65)];
        yield 'слово, начинающееся с erid' => ['Реклама. Рекламодатель ООО Eridanus ИНН 7700000000 , https://eridanus.ru'];
        yield 'значение без разделителя' => ['Реклама. erid2SDnjTEST001'];
        yield 'двоеточие без значения' => ['Реклама. erid: , https://x.ru'];
        yield 'битый UTF-8' => ["erid 2SDnjTEST001 \xff"];
    }

    #[DataProvider('withoutErid')]
    public function testNoErid(?string $marking): void
    {
        self::assertNull(AdMarking::erid($marking));
    }

    public function testLastEridWins(): void
    {
        self::assertSame('2SDnjSECOND', AdMarking::erid('Реклама. erid 2SDnjFIRST1, erid 2SDnjSECOND'));
        self::assertSame('2SDnjTEST001', AdMarking::erid('Реклама. ООО Eridanus, https://eridanus.ru erid 2SDnjTEST001'));
    }

    public function testIsErid(): void
    {
        self::assertTrue(AdMarking::isErid('2SDnjcZqvUG'));
        self::assertTrue(AdMarking::isErid('Kra23dyAk'));
        self::assertFalse(AdMarking::isErid(''));
        self::assertFalse(AdMarking::isErid('abc'));
        self::assertFalse(AdMarking::isErid('2SDnj cZqvUG'));
        self::assertFalse(AdMarking::isErid("2SDnjcZqvUG\n"));
        self::assertFalse(AdMarking::isErid('2SDnj%20UG'));
        self::assertFalse(AdMarking::isErid(str_repeat('a', 65)));
    }
}
