<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Xml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\XmlRecordReader;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\XmlRecords;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class XmlRecordReaderTest extends TestCase
{
    private const DOCTYPE = '<!DOCTYPE yml_catalog SYSTEM "shops.dtd">';
    private const PATH = ['yml_catalog', 'offers', 'offer'];

    public function testReadsRecordsByPathOnly(): void
    {
        // у корня и у записей есть одноимённые элементы <name> — записями считается только путь целиком
        $xml = '<yml_catalog><name>Где Слон?</name><offers><offer><name>A</name></offer><offer><name>B</name></offer></offers>'
            . '<other><offer><name>не запись</name></offer></other></yml_catalog>';

        self::assertSame(['A', 'B'], self::read($xml)->records());
    }

    public function testCapturesHeaderValues(): void
    {
        $xml = '<yml_catalog><info><documents_number>5322</documents_number></info><offers/></yml_catalog>';

        $records = (new XmlRecordReader())->read($xml, 'поиска', self::PATH, self::nameOf(), ['yml_catalog/info/documents_number']);

        self::assertSame('5322', $records->captured('yml_catalog/info/documents_number'));
        self::assertNull($records->captured('yml_catalog/info/absent'));
        self::assertSame([], $records->records());
    }

    public function testExactDoctypeIsAllowed(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . self::DOCTYPE . '<yml_catalog><offers><offer><name>A</name></offer></offers></yml_catalog>';

        self::assertSame(['A'], self::read($xml, self::DOCTYPE)->records());
        self::assertSame(['A'], self::read('<yml_catalog><offers><offer><name>A</name></offer></offers></yml_catalog>', self::DOCTYPE)->records());
        self::assertSame(['A'], self::read("\xEF\xBB\xBF<?xml version=\"1.0\"?>\r\n" . self::DOCTYPE . "\n<yml_catalog><offers><offer><name>A</name></offer></offers></yml_catalog>", self::DOCTYPE)->records());
    }

    public function testDoctypeOutsideOfPrologIsRejected(): void
    {
        // разрешённый DOCTYPE сверяется по сырому прологу, а не по сериализации libxml (она зависит от версии)
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('неожиданный DOCTYPE: <!DOCTYPE yml_catalog SYSTEM "shops.dtd">');

        self::read('<?xml version="1.0"?><!-- x -->' . self::DOCTYPE . '<yml_catalog><offers/></yml_catalog>', self::DOCTYPE);
    }

    public function testRejectedDoctypeMessageHidesInternalSubset(): void
    {
        try {
            self::read('<!DOCTYPE yml_catalog SYSTEM "shops.dtd" [<!ENTITY secret "VALUE">]><yml_catalog><offers/></yml_catalog>', self::DOCTYPE);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('неожиданный DOCTYPE: <!DOCTYPE yml_catalog SYSTEM "shops.dtd" […]>', $e->getMessage());
            self::assertStringNotContainsString('VALUE', $e->getMessage());
        }
    }

    public function testMissingRecordContainerIsBrokenDocument(): void
    {
        // другая раскладка (стандартный YML: shop/offers/offer) — не «ничего не найдено», а ошибка документа
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('нет элемента «yml_catalog/offers»');

        self::read('<yml_catalog><shop><offers><offer><name>A</name></offer></offers></shop></yml_catalog>');
    }

    public function testAnyDoctypeIsRejectedWhenNoneAllowed(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('DOCTYPE');

        self::read(self::DOCTYPE . '<yml_catalog/>');
    }

    #[DataProvider('doctypeAttacks')]
    public function testOtherDoctypesAreRejectedWithoutNetwork(string $doctype): void
    {
        $xml = '<?xml version="1.0"?>' . $doctype . '<yml_catalog><offers><offer><name>&x;</name></offer></offers></yml_catalog>';
        $started = microtime(true);

        try {
            self::read($xml, self::DOCTYPE);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            // отказ — по DOCTYPE или раньше, на неопределённой сущности; главное: быстро, без сети и без подстановки
            self::assertLessThan(1.0, microtime(true) - $started);
            self::assertStringNotContainsString('INJECTED', $e->getMessage());
            self::assertStringNotContainsString('root:', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function doctypeAttacks(): iterable
    {
        yield 'внутренняя сущность' => ['<!DOCTYPE yml_catalog SYSTEM "shops.dtd" [<!ENTITY x "INJECTED">]>'];
        yield 'локальный файл' => ['<!DOCTYPE yml_catalog SYSTEM "file:///etc/passwd">'];
        yield 'внешний DTD по сети' => ['<!DOCTYPE yml_catalog SYSTEM "http://10.255.255.1/shops.dtd">'];
        yield 'PUBLIC' => ['<!DOCTYPE yml_catalog PUBLIC "-//x" "y">'];
        yield 'параметрическая сущность' => ['<!DOCTYPE yml_catalog [<!ENTITY % p SYSTEM "http://10.255.255.1/x">%p;]>'];
        yield 'внешняя сущность-файл' => ['<!DOCTYPE yml_catalog [<!ENTITY x SYSTEM "file:///etc/passwd">]>'];
    }

    public function testBillionLaughsIsRejected(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE yml_catalog [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
            . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]><yml_catalog><offers><offer><name>&c;</name></offer></offers></yml_catalog>';

        $this->expectException(UnexpectedResponseException::class);

        self::read($xml, self::DOCTYPE);
    }

    #[DataProvider('notDocuments')]
    public function testBrokenDocument(string $body): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new \RuntimeException('PHP warning: ' . $message);
        });
        try {
            self::read($body, self::DOCTYPE);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString(strlen($body) . ' байт', $e->getMessage());
            self::assertStringContainsString('Ответ поиска', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDocuments(): iterable
    {
        yield 'пусто' => [''];
        yield 'пробелы' => ['  '];
        yield 'HTML 403' => ['This affiliate token does not exists'];
        yield 'JSON' => ['[{"_id":1}]'];
        yield 'обрезанный ответ' => [substr(Fixtures::read('search/search.xml'), 0, 6000)];
        yield 'невалидный UTF-8' => ["<yml_catalog><offers><offer><name>\xff\xfe</name></offer></offers></yml_catalog>"];
        yield 'чужой корень' => ['<shops><shop/></shops>'];
    }

    public function testBrokenRecordsAreSkipped(): void
    {
        $xml = '<yml_catalog><offers><offer><name>A</name></offer><offer/><offer><name>C</name></offer></offers></yml_catalog>';

        $records = self::read($xml);

        self::assertSame(['A', 'C'], $records->records());
        self::assertSame(['запись без name'], $records->skipped());
    }

    public function testDocumentWithoutParsedRecordsIsBroken(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('ни одна запись не разобрана (2)');

        self::read('<yml_catalog><offers><offer/><offer/></offers></yml_catalog>');
    }

    public function testNoRecordsIsNotAnError(): void
    {
        self::assertSame([], self::read('<yml_catalog><offers/></yml_catalog>')->records());
    }

    public function testLibxmlStateIsRestored(): void
    {
        foreach ([false, true] as $initial) {
            $previous = libxml_use_internal_errors($initial);
            try {
                self::read('<yml_catalog><offers/></yml_catalog>');
                self::assertSame($initial, libxml_use_internal_errors($initial));
                try {
                    self::read('<yml_catalog><offers>');
                } catch (UnexpectedResponseException) {
                }
                self::assertSame($initial, libxml_use_internal_errors($initial));
                self::assertSame([], libxml_get_errors());
            } finally {
                libxml_use_internal_errors($previous);
            }
        }
    }

    /**
     * @return XmlRecords<string>
     */
    private static function read(string $xml, ?string $doctype = null): XmlRecords
    {
        return (new XmlRecordReader())->read($xml, 'поиска', self::PATH, self::nameOf(), [], $doctype);
    }

    /**
     * @return \Closure(\SimpleXMLElement): string
     */
    private static function nameOf(): \Closure
    {
        return static function (\SimpleXMLElement $record): string {
            $name = (string) $record->name;

            return $name !== '' ? $name : throw new UnexpectedResponseException('запись без name');
        };
    }

    public function testWildcardRecordReadsEveryChildOfContainer(): void
    {
        $xml = '<gdeslon-coupons><merchant_id><list-item>a</list-item></merchant_id><kind><list-item>b</list-item></kind></gdeslon-coupons>';

        $records = (new XmlRecordReader())->read($xml, 'ошибок', ['gdeslon-coupons', '*'], static fn (\SimpleXMLElement $e): string => $e->getName() . '=' . \Webreboot\GdeSlon\Infrastructure\Api\Xml\SimpleXml::text($e, 'list-item'));

        self::assertSame(['merchant_id=a', 'kind=b'], $records->records());
        self::assertSame([], (new XmlRecordReader())->read('<gdeslon-coupons/>', 'ошибок', ['gdeslon-coupons', '*'], static fn (\SimpleXMLElement $e): string => 'x')->records());

        $this->expectException(\Webreboot\GdeSlon\Exception\UnexpectedResponseException::class);
        (new XmlRecordReader())->read('<!DOCTYPE gdeslon-coupons><gdeslon-coupons/>', 'ошибок', ['gdeslon-coupons', '*'], static fn (\SimpleXMLElement $e): string => 'x');
    }
}
