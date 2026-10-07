<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Postback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Postback\InvalidPostbackException;
use Webreboot\GdeSlon\Interface\Postback\NonScalarValue;
use Webreboot\GdeSlon\Interface\Postback\XmlBodyParser;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class XmlBodyParserTest extends TestCase
{
    public function testFlatDocument(): void
    {
        $fields = XmlBodyParser::parse(Fixtures::read('postback/xml-all-macros.xml'));

        self::assertSame('2573', $fields['merchant_id']);
        self::assertSame('3', $fields['state']);
        self::assertSame('Магазин «Тест» & Co', $fields['offer_name']);
        self::assertSame('тест 1+2 &x=y', $fields['sub_id']);
        self::assertSame('"кавычки" <b>', $fields['sub_id2']);
        self::assertSame('', $fields['action_time']);
        self::assertSame('', $fields['sub_id3']);
    }

    public function testAnyRootAttributesIgnoredNestedMarked(): void
    {
        $fields = XmlBodyParser::parse('<?xml version="1.0"?><conversion id="x"><merchant_id kind="int">1</merchant_id><extra><a>1</a></extra></conversion>');

        self::assertSame('1', $fields['merchant_id']);
        self::assertInstanceOf(NonScalarValue::class, $fields['extra']);
    }

    #[DataProvider('attacks')]
    public function testDoctypeIsRejectedWithoutNetwork(string $fixture): void
    {
        $started = microtime(true);
        try {
            XmlBodyParser::parse(Fixtures::read('postback/' . $fixture));
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(400, $e->responseStatus());
            self::assertStringContainsString('DOCTYPE', $e->getMessage());
            self::assertStringNotContainsString('root:', $e->getMessage(), 'содержимого файлов нет');
        }
        self::assertLessThan(1.0, microtime(true) - $started, 'без обращения к сети');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function attacks(): iterable
    {
        yield 'XXE' => ['xml-xxe.xml'];
        yield 'billion laughs' => ['xml-billion-laughs.xml'];
        yield 'внешний DTD' => ['xml-external-dtd.xml'];
    }

    #[DataProvider('broken')]
    public function testBrokenDocument(string $body, string $message): void
    {
        set_error_handler(static function (int $level, string $text): never {
            throw new \RuntimeException('PHP warning: ' . $text);
        });
        $previous = libxml_use_internal_errors(false);
        try {
            XmlBodyParser::parse($body);
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(400, $e->responseStatus());
            self::assertStringContainsString($message, $e->getMessage());
            self::assertFalse(libxml_use_internal_errors(false), 'состояние libxml восстановлено');
        } finally {
            libxml_use_internal_errors($previous);
            restore_error_handler();
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function broken(): iterable
    {
        yield 'пусто' => ['  ', 'пуст'];
        yield 'не XML' => ['merchant_id=1', 'XML'];
        yield 'обрезан' => ['<root><merchant_id>1</merchant_id><state>', 'XML'];
        yield 'повтор элемента' => ['<root><state>1</state><state>2</state></root>', 'state'];
    }

    #[DataProvider('otherEncodings')]
    public function testOnlyUtf8ReachesLibxml(string $fixture): void
    {
        try {
            XmlBodyParser::parse(Fixtures::read('postback/' . $fixture));
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(400, $e->responseStatus());
            self::assertStringNotContainsString('INJECTED', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherEncodings(): iterable
    {
        yield 'UTF-16BE с DTD' => ['xml-xxe-utf16be.xml'];
        yield 'UTF-16LE с DTD без BOM' => ['xml-xxe-utf16le.xml'];
        yield 'UTF-7 со скрытым DOCTYPE' => ['xml-utf7-doctype.xml'];
    }

    public function testUtf8WithBomIsAccepted(): void
    {
        self::assertSame(['merchant_id' => '1'], XmlBodyParser::parse("\xEF\xBB\xBF<r><merchant_id>1</merchant_id></r>"));
    }

    public function testDoctypeTextInsideDocumentIsData(): void
    {
        // User-Agent или Referer покупателя с «<!DOCTYPE» не должен ронять postback
        $fields = XmlBodyParser::parse('<r><merchant_id>1</merchant_id><user_agent><![CDATA[Mozilla <!DOCTYPE html>]]></user_agent>'
            . '<!-- <!ENTITY x "y"> --><user_referrer><![CDATA[https://e.com/?q=<!ENTITY]]></user_referrer></r>');

        self::assertSame('Mozilla <!DOCTYPE html>', $fields['user_agent']);
        self::assertSame('https://e.com/?q=<!ENTITY', $fields['user_referrer']);
    }

    public function testDoctypeAfterPrologItemsIsRejected(): void
    {
        $this->expectException(InvalidPostbackException::class);
        $this->expectExceptionMessage('DOCTYPE');

        XmlBodyParser::parse("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<?pi x?><!-- c -->\n<!doctype r [<!ENTITY a \"b\">]><r><merchant_id>&a;</merchant_id></r>");
    }

    public function testDeclaredEncodingIsIgnored(): void
    {
        self::assertSame(['sub_id' => 'Привет'], XmlBodyParser::parse('<?xml version="1.0" encoding="ISO-8859-1"?><r><sub_id>Привет</sub_id></r>'), 'libxml ' . LIBXML_DOTTED_VERSION);
        self::assertSame(['sub_id' => 'Привет'], XmlBodyParser::parse('<?xml version="1.0" encoding="windows-1251"?><r><sub_id>Привет</sub_id></r>'), 'libxml ' . LIBXML_DOTTED_VERSION);
    }
}
