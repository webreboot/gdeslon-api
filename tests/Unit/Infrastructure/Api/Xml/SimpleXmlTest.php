<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Xml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\SimpleXml;

final class SimpleXmlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cdataEntities(): iterable
    {
        yield 'кавычки' => ['<![CDATA[Джеггинсы &quot;Анабель&quot;]]>', 'Джеггинсы "Анабель"'];
        yield 'ёлочки и тире' => ['<![CDATA[&laquo;Слон&raquo; &mdash; да &ndash; нет]]>', '«Слон» — да – нет'];
        yield 'числовые' => ['<![CDATA[скидка 10&#37; &#40;до&#41;]]>', 'скидка 10% (до)'];
        yield 'апостроф' => ['<![CDATA[Dr.&#39;s &apos;ok&apos;]]>', "Dr.'s 'ok'"];
    }

    #[DataProvider('cdataEntities')]
    public function testCdataEntitiesDecodedOnce(string $content, string $expected): void
    {
        self::assertSame($expected, SimpleXml::decodedText(self::element($content), 'v'));
    }

    public function testCdataDoubleEscapedDecodedOnlyOnce(): void
    {
        self::assertSame('Wi-Fi &mdash; 5 ГГц, Brand&trade;', SimpleXml::decodedText(self::element('<![CDATA[Wi-Fi &amp;mdash; 5 ГГц, Brand&amp;trade;]]>'), 'v'));
    }

    public function testPlainTextNotDecodedAgain(): void
    {
        self::assertSame('Платье &quot;Анабель&quot; & Co', SimpleXml::decodedText(self::element('Платье &amp;quot;Анабель&amp;quot; &amp; Co'), 'v'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rawAmpersands(): iterable
    {
        yield 'пробелы вокруг' => ['<![CDATA[Cica & Ceramide]]>', 'Cica & Ceramide'];
        yield 'без точки с запятой' => ['<![CDATA[AliExpress RU&CIS]]>', 'AliExpress RU&CIS'];
        yield 'неизвестная сущность' => ['<![CDATA[A &unknownentity; B]]>', 'A &unknownentity; B'];
    }

    #[DataProvider('rawAmpersands')]
    public function testRawAmpersandKept(string $content, string $expected): void
    {
        self::assertSame($expected, SimpleXml::decodedText(self::element($content), 'v'));
    }

    public function testNbspAndCyrillic(): void
    {
        self::assertSame("Размер\u{00A0}M, цвет\u{00A0}«ёлка»", SimpleXml::decodedText(self::element('<![CDATA[Размер&nbsp;M, цвет&nbsp;&laquo;ёлка&raquo;]]>'), 'v'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlReferences(): iterable
    {
        yield 'escape' => ['&#27;'];
        yield 'ноль' => ['&#0;'];
        yield 'шестнадцатеричный' => ['&#x1B;'];
        yield 'вертикальная табуляция' => ['&#11;'];
    }

    #[DataProvider('controlReferences')]
    public function testControlCharacterReferencesStayText(string $reference): void
    {
        $text = (string) SimpleXml::decodedText(self::element('<![CDATA[a' . $reference . 'b]]>'), 'v');

        self::assertSame('a' . $reference . 'b', $text);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text);
    }

    public function testHtmlTagEntitiesBecomeTags(): void
    {
        self::assertSame('Хлопок<br/>Состав: 100%', SimpleXml::decodedText(self::element('<![CDATA[Хлопок&lt;br/&gt;Состав: 100&#37;]]>'), 'v'));
    }

    public function testMixedContent(): void
    {
        self::assertSame('ООО &quot;Слон&quot; «Сад»', SimpleXml::decodedText(self::element('ООО &amp;quot;Слон&amp;quot; <![CDATA[&laquo;Сад&raquo;]]>'), 'v'));
        self::assertSame('&lt; «до» &amp; ', SimpleXml::decodedText(self::element('&amp;lt; <![CDATA[&laquo;до&raquo;]]> &amp;amp; '), 'v'));
        self::assertSame('«а» &quot; «б»', SimpleXml::decodedText(self::element('<![CDATA[&laquo;а&raquo;]]> &amp;quot; <![CDATA[&laquo;б&raquo;]]>'), 'v'));
    }

    public function testNestedElementIsIgnored(): void
    {
        self::assertSame('&quot;a&quot; «c»', SimpleXml::decodedText(self::element('&amp;quot;a&amp;quot;<i><![CDATA[&laquo;b&raquo;]]></i> <![CDATA[&laquo;c&raquo;]]>'), 'v'));
    }

    public function testMixedContentInWindows1251Document(): void
    {
        $element = simplexml_load_string("<?xml version=\"1.0\" encoding=\"windows-1251\"?><r><v>\xc2\xf1\xb8 &amp;quot; <![CDATA[&laquo;\xe4\xe0&raquo;]]></v></r>");
        self::assertInstanceOf(\SimpleXMLElement::class, $element);

        self::assertSame('Всё &quot; «да»', SimpleXml::decodedText($element, 'v'));
    }

    public function testEmptyAndMissing(): void
    {
        $element = self::element('<![CDATA[]]>');

        self::assertNull(SimpleXml::decodedText($element, 'v'));
        self::assertNull(SimpleXml::decodedText($element, 'missing'));
        self::assertNull(SimpleXml::decodedText(self::element('<![CDATA[   ]]>'), 'v'));
        self::assertNull(SimpleXml::decodedText(self::element(''), 'v'));
        self::assertNull(SimpleXml::decodedOptional($element, 'v'));
        self::assertNull(SimpleXml::decodedOptional($element, 'missing'));
    }

    public function testDecodedOptionalTrims(): void
    {
        self::assertSame('«Слон»', SimpleXml::decodedOptional(self::element("<![CDATA[ &laquo;Слон&raquo;\n]]>"), 'v'));
        self::assertSame(" «Слон»\n", SimpleXml::decodedText(self::element("<![CDATA[ &laquo;Слон&raquo;\n]]>"), 'v'));
    }

    public function testWindows1251Document(): void
    {
        $element = simplexml_load_string("<?xml version=\"1.0\" encoding=\"windows-1251\"?><r><v><![CDATA[\xc2\xf1\xb8 &laquo;\xe4\xe0&raquo;]]></v><p>\xc2\xf1\xb8 &amp;laquo;</p></r>");
        self::assertInstanceOf(\SimpleXMLElement::class, $element);

        self::assertSame('Всё «да»', SimpleXml::decodedText($element, 'v'));
        self::assertSame('Всё &laquo;', SimpleXml::decodedText($element, 'p'));
    }

    private static function element(string $content): \SimpleXMLElement
    {
        $element = simplexml_load_string('<r><v>' . $content . '</v></r>');
        self::assertInstanceOf(\SimpleXMLElement::class, $element);

        return $element;
    }
}
