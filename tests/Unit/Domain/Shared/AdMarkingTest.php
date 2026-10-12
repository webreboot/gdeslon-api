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
        yield 'знак равенства' => ['Реклама. ООО «Пример» erid=2SDnjTEST322', '2SDnjTEST322'];
        yield 'равенство с пробелами' => ['Реклама. erid = 2SDnjTEST323', '2SDnjTEST323'];
        yield 'двоеточие вплотную' => ['Реклама. ERID:2SDnjTEST325', '2SDnjTEST325'];
        yield 'смешанный регистр' => ['Реклама. Erid 2SDnjTEST326', '2SDnjTEST326'];
        yield 'неразрывный пробел' => ["Реклама. erid\u{00A0}2SDnjTEST324", '2SDnjTEST324'];
        yield 'запятая и текст' => ['Реклама. erid 2SDnjTEST328, ИНН 7700000000', '2SDnjTEST328'];
        yield 'в скобках' => ['Реклама (erid 2SDnjTEST329)', '2SDnjTEST329'];
        yield 'в кавычках-ёлочках' => ['Реклама «erid 2SDnjTEST330»', '2SDnjTEST330'];
        yield 'кириллица перед словом' => ['Реклама. ООО «Эрид» erid 2SDnjTEST327', '2SDnjTEST327'];
        yield 'Kra' => ['Реклама. erid Kra2449mN', 'Kra2449mN'];
        yield 'в кавычках-лапках' => ['Реклама „erid 2SDnjTEST01“', '2SDnjTEST01'];
        yield 'перед HTML-тегом' => ['Реклама. erid 2SDnjTEST01<br>', '2SDnjTEST01'];
        yield 'цифра в конце значения' => ['Реклама. erid abcdEFG1', 'abcdEFG1'];
        yield 'сущность &nbsp; после значения' => ['Реклама. erid 2SDnjTEST01&nbsp;', '2SDnjTEST01'];
        yield 'сущность &#160; после значения' => ['Реклама. erid 2SDnjTEST01&#160;', '2SDnjTEST01'];
        yield 'сущность &#xA0; после значения' => ['Реклама. erid 2SDnjTEST01&#xA0;', '2SDnjTEST01'];
        yield 'сущность &#XA0; после значения' => ['Реклама. erid 2SDnjTEST01&#XA0;', '2SDnjTEST01'];
        yield 'сущность пробела и текст' => ['Реклама. erid 2SDnjTEST01&nbsp;ООО «Пример»', '2SDnjTEST01'];
        yield 'скобка и точка' => ['Реклама (erid 2SDnjTEST01).', '2SDnjTEST01'];
        yield 'ёлочка и запятая' => ['Реклама «erid 2SDnjTEST01»,', '2SDnjTEST01'];
        yield 'скобка и ёлочка' => ['Реклама («erid 2SDnjTEST01»)', '2SDnjTEST01'];
        yield 'точка и скобка' => ['Реклама. erid 2SDnjTEST01.)', '2SDnjTEST01'];
        yield 'апостроф и точка' => ["Реклама. erid 2SDnjTEST01'.", '2SDnjTEST01'];
        yield 'кавычка в конце' => ['Реклама. erid 2SDnjTEST01"', '2SDnjTEST01'];
        yield 'две ёлочки' => ['Реклама «erid 2SDnjTEST01»»', '2SDnjTEST01'];
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
        yield 'дефис после значения' => ['Реклама. ERID: 2SDn-TEST'];
        yield 'подчёркивание после значения' => ['Реклама. erid 2SDnj_TEST'];
        yield 'косая черта после значения' => ['Реклама. erid 2SDnj/TEST'];
        yield 'точка внутри значения' => ['Реклама. erid 2SDnj.TEST'];
        yield 'процент после значения' => ['Реклама. erid=2SDnj%2BTEST'];
        yield 'плюс после значения' => ['Реклама. erid 2SDnj+TEST'];
        yield 'равенство после значения' => ['Реклама. erid=2SDnjTEST=='];
        yield 'кириллица сразу после' => ['Реклама. erid 2SDnjTESTабв'];
        yield 'равенство без значения' => ['Реклама. erid='];
        yield 'слово как значение' => ['Реклама. erid: erid'];
        yield 'sub_erid' => ['Реклама. sub_erid=2SDnjTEST331'];
        yield 'дефис перед словом' => ['Реклама. x-erid 2SDnjTEST332'];
        yield 'erid в адресе после ?' => ['Реклама. https://x.ru/?erid=2SDnjTEST330&utm=1'];
        yield 'erid в адресе после &' => ['Реклама. https://x.ru/?a=1&erid=2SDnjTEST330'];
        yield 'кавычка перед значением' => ['Реклама. erid «2SDnjTEST333»'];
        yield 'кириллическая е в слове' => ["Реклама. \u{0435}rid 2SDnjTEST334"];
        yield 'многоточие после значения' => ['Реклама. erid 2SDnjcZ...'];
        yield 'две точки внутри значения' => ['Реклама. erid 2SDnj..TEST'];
        yield 'знак Кельвина в значении' => ["Реклама. erid 2\u{212A}DnjTEST01"];
        yield 'длинная s в значении' => ["Реклама. erid 2\u{017F}DnjTEST01"];
        yield 'заглушка undefined' => ['Реклама. ООО «Пример», erid: undefined'];
        yield 'заглушка null' => ['Реклама. ООО «Пример», erid: null'];
        yield 'значение без цифры' => ['Реклама. erid abcdEFGH'];
        yield 'тире после значения' => ['Реклама. erid 2SDnjTEST01—ООО'];
        yield 'сущность &shy; внутри значения' => ['Реклама. erid 2SDnj&shy;TEST01'];
        yield 'сущность &#8209; внутри значения' => ['Реклама. erid 2SDnj&#8209;TEST01'];
        yield '& внутри значения' => ['Реклама. erid 2SDnj&TEST'];
        yield '&amp; внутри значения' => ['Реклама. erid 2SDnj&amp;TEST'];
        yield '&nbsp без точки с запятой' => ['Реклама. erid 2SDnjX1&nbspx'];
        yield '# внутри значения' => ['Реклама. erid 2SDnj#TEST'];
        yield '# после значения' => ['Реклама. erid 2SDnjX1#'];
        yield 'апостроф внутри значения' => ["Реклама. erid 2SDnj'TEST"];
        yield 'скобка внутри значения' => ['Реклама. erid 2SDnj)TEST'];
        yield 'лапка внутри значения' => ['Реклама. erid 2SDnj„TEST'];
        yield 'ёлочка и цифра' => ['Реклама. erid 2SDnj»1'];
        yield 'кавычка и кириллица' => ['Реклама. erid 2SDnj"ТЕСТ'];
        yield 'точка, скобка и буква' => ['Реклама. erid 2SDnjX1.)a'];
        yield 'кавычка, скобка и буква' => ['Реклама. erid 2SDnj")TEST'];
        yield 'скобка и дефис' => ['Реклама. erid 2SDnj)-TEST'];
        yield 'скобка и подчёркивание' => ['Реклама. erid 2SDnj)_TEST'];
        yield 'мягкий перенос' => ["Реклама. erid 2SDnj\u{00AD}TEST01"];
        yield 'неразрывный дефис' => ["Реклама. erid 2SDnj\u{2011}TEST01"];
        yield 'хэштег' => ['Реклама. #erid 2SDnjX1'];
        yield 'путь перед словом' => ['Реклама. /erid 2SDnjX1'];
        yield 'параметр через двоеточие' => ['Реклама. ?erid:2SDnjX1'];
        yield 'путь с двоеточием' => ['Реклама. x.ru/erid:2SDnjURL01'];
    }

    #[DataProvider('withoutErid')]
    public function testNoErid(?string $marking): void
    {
        self::assertNull(AdMarking::erid($marking));
    }

    public function testFirstEridWins(): void
    {
        self::assertSame('2SDnjFIRST1', AdMarking::erid('Реклама. erid 2SDnjFIRST1, erid 2SDnjSECOND'));
        self::assertSame('2SDnjTEST001', AdMarking::erid('Реклама. ООО Eridanus, https://eridanus.ru erid 2SDnjTEST001'));
        self::assertSame('2SDnjTEST001', AdMarking::erid('Реклама. ООО «Erid Group», erid 2SDnjTEST001'));
        self::assertSame('2SDnjTEST001', AdMarking::erid('Реклама. Рекламодатель Erid Labs ИНН 7700000000 erid 2SDnjTEST001'));
    }

    #[DataProvider('realForms')]
    public function testFoundEridPassesIsErid(string $marking): void
    {
        self::assertTrue(AdMarking::isErid((string) AdMarking::erid($marking)));
    }

    public function testEmptyCandidateThenNext(): void
    {
        self::assertSame('2SDnjTEST333', AdMarking::erid('Реклама. erid: , erid 2SDnjTEST333'));
        self::assertSame('2SDnjTEST334', AdMarking::erid('Реклама. erid: erid 2SDnjTEST334'));
        self::assertSame('2SDnjTEST335', AdMarking::erid('Реклама. erid erid 2SDnjTEST335'));
    }

    public function testTruncatedThenValid(): void
    {
        self::assertSame('2SDnjTEST336', AdMarking::erid('Реклама. erid 2SDn-TEST, erid 2SDnjTEST336'));
    }

    public function testTextEridPreferredOverUrlInMarking(): void
    {
        self::assertSame('2SDnjTEXT001', AdMarking::erid('Реклама. https://x.ru/?erid=2SDnjURL0001&a=1 erid 2SDnjTEXT001'));
        self::assertSame('2SDnjTEXT001', AdMarking::erid('Реклама. https://x.ru/?a=1&amp;erid=2SDnjURL0001&amp;b=2 erid 2SDnjTEXT001'));
        self::assertSame('2SDnjTEXT001', AdMarking::erid('Реклама. https://x.ru/#erid=2SDnjURL0001 erid 2SDnjTEXT001'));
        self::assertSame('2SDnjTEXT001', AdMarking::erid('Реклама. https://x.ru/p;erid=2SDnjURL0001 erid 2SDnjTEXT001'));
        self::assertSame('2SDnjTEXT001', AdMarking::erid('Реклама. https://x.ru/p/erid=2SDnjURL0001 erid 2SDnjTEXT001'));
        self::assertSame('2SDnjTXT01', AdMarking::erid('Реклама. https://x.ru/p:erid=2SDnjURL01 erid 2SDnjTXT01'));
        self::assertSame('2SDnjTXT01', AdMarking::erid('Реклама. p.erid=2SDnjURL01 erid 2SDnjTXT01'));
        self::assertSame('2SDnjTXT01', AdMarking::erid('Реклама. https://x.ru/?q=a+erid=2SDnjURL01 erid 2SDnjTXT01'));
        self::assertSame('2SDnjTXT01', AdMarking::erid('Реклама. x.ru/erid:2SDnjURL01 erid 2SDnjTXT01'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function equalsSeparator(): iterable
    {
        yield 'начало текста' => ['erid=2SDnjX1', '2SDnjX1'];
        yield 'заглавными' => ['ERID=2SDnjX1', '2SDnjX1'];
        yield 'после пробела' => ['Реклама. erid=2SDnjX1', '2SDnjX1'];
        yield 'после NBSP' => ["Реклама.\u{00A0}erid=2SDnjX1", '2SDnjX1'];
        yield 'после табуляции' => ["Реклама.\terid=2SDnjX1", '2SDnjX1'];
        yield 'после перевода строки, с пробелами' => ["Реклама.\nerid = 2SDnjX1", '2SDnjX1'];
        yield 'значение на новой строке' => ["Реклама. erid=\n2SDnjX1", '2SDnjX1'];
        yield 'в скобках' => ['Реклама (erid=2SDnjX1)', null];
        yield 'в ёлочках' => ['Реклама «erid=2SDnjX1»', null];
        yield 'после точки вплотную' => ['Реклама.erid=2SDnjX1', null];
        yield 'адрес с двоеточием' => ['Реклама. https://x.ru/p:erid=2SDnjURL01', null];
        yield 'параметр через плюс' => ['?q=a+erid=2SDnjX1', null];
        yield 'двойное равенство' => ['erid=erid=2SDnjX1', null];
    }

    #[DataProvider('equalsSeparator')]
    public function testEqualsSeparatorNeedsStartOrWhitespace(string $marking, ?string $erid): void
    {
        self::assertSame($erid, AdMarking::erid($marking));
    }

    public function testLongWhitespaceRuns(): void
    {
        self::assertSame('2SDnjX1', AdMarking::erid(str_repeat(' ', 100000) . 'erid 2SDnjX1'));
        self::assertSame(PREG_NO_ERROR, preg_last_error());
        self::assertNull(AdMarking::erid('erid' . str_repeat(' ', 100000) . 'x'));
        self::assertSame(PREG_NO_ERROR, preg_last_error());
        self::assertSame('2SDnjX1', AdMarking::erid('erid=' . str_repeat(' ', 100000) . '2SDnjX1'));
        self::assertSame(PREG_NO_ERROR, preg_last_error());
        self::assertSame('2SDnjX1', AdMarking::erid(str_repeat('erid 2SDnj#X ', 1000) . 'erid 2SDnjX1'));
        self::assertSame(PREG_NO_ERROR, preg_last_error());
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function consumerParity(): iterable
    {
        yield 'два erid' => ['Реклама. Рекламодатель ООО «Пример» erid 2SDnjTEST321A, erid 2SDnjTEST321B', '2SDnjTEST321A'];
        yield 'erid=' => ['Реклама. Рекламодатель ООО «Пример» erid=2SDnjTEST322', '2SDnjTEST322'];
        yield 'обрезанный' => ['Реклама. Рекламодатель ООО «Пример» ERID: 2SDn-TEST', null];
    }

    #[DataProvider('consumerParity')]
    public function testConsumerParity(string $marking, ?string $erid): void
    {
        self::assertSame($erid, AdMarking::erid($marking));
    }

    public function testLongMarkingWithManyCandidates(): void
    {
        self::assertSame('2SDnjTEST337', AdMarking::erid(str_repeat('erid 2SDn-X ', 1000) . 'erid 2SDnjTEST337'));
        self::assertSame(str_repeat('a', 63) . '1', AdMarking::erid('erid ' . str_repeat('a', 63) . '1'));
        self::assertNull(AdMarking::erid('erid ' . str_repeat('a', 64) . '1 '));
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
        foreach (['None', 'null', 'NULL', 'undefined', 'abcdEFGH'] as $placeholder) {
            self::assertFalse(AdMarking::isErid($placeholder), $placeholder);
        }
        foreach (['2024', 'abcdEFG1', '1abc'] as $erid) {
            self::assertTrue(AdMarking::isErid($erid), $erid);
        }
    }
}
