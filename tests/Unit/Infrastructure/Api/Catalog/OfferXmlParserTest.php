<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\OfferXmlParser;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class OfferXmlParserTest extends TestCase
{
    private const HEAD = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE yml_catalog SYSTEM "shops.dtd"><yml_catalog date="2026-10-07 16:41:12">'
        . '<info><documents_number>%s</documents_number></info><offers>';
    private const VALID = '<offer available="true" merchant_id="1" id="9" article="a" gs_category_id="5"><price>10</price>'
        . '<currencyId>RUR</currencyId><name><![CDATA[Валидный]]></name><url>https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1</url></offer>';

    public function testParsesRealFixture(): void
    {
        $result = self::fixture();

        self::assertSame(
            ['14367733380732236000', '7300392645675467000', '725748519890628700', '7273985276702969000', '3377556719066259000', '2421799661623834600', '7586175759118006000', '13547762558345343000'],
            array_map(static fn (Offer $o): string => $o->id(), $result->offers()),
        );
        self::assertSame([], $result->skipped());
        self::assertSame(5322, $result->total());
    }

    public function testBasicOffer(): void
    {
        $offer = self::offer('14367733380732236000');

        self::assertSame(107054, $offer->merchantId()->value());
        self::assertSame('578237', $offer->article());
        self::assertSame(26, $offer->categoryId()?->value());
        self::assertSame('100 RUR', (string) $offer->price());
        self::assertNull($offer->oldPrice());
        self::assertSame('3.64 RUR', (string) $offer->charge());
        self::assertTrue($offer->isAvailable());
        self::assertSame('https://imgng.gdeslon.ru/commodities/406571949/pictures/75e8da9a3ca9e0c3a2dba8b146ddeb28/big.jpg', $offer->picture());
        self::assertSame('https://imgng.gdeslon.ru/commodities/406571949/pictures/75e8da9a3ca9e0c3a2dba8b146ddeb28/small.jpg', $offer->thumbnail());
        self::assertSame('https://cdn.amwine.ru/upload/iblock/e79/6xay7rt5fdrsuwy9vp3eqpwgxlskorle.png', $offer->originalPicture());
        self::assertSame('Пакет подарочный М (180*227*100)', $offer->name());
        self::assertNull($offer->description());
        self::assertSame('ГК Горчаков', $offer->vendor());
        self::assertNull($offer->model());
        self::assertSame('https://amwine.ru/catalog/aksessuary/podarochnye_pakety_i_korobki/paket_podarochnyy_m_180_227_100/', $offer->productUrl());
        self::assertStringStartsWith('Реклама. Рекламодатель АМ КРАСНОГОРСК ИНН 5032280208', (string) $offer->adMarking());
        self::assertStringStartsWith('https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=107054&goto=https%3A%2F%2Famwine.ru%2F', $offer->affiliateLink());
        self::assertStringContainsString('&erid=', $offer->affiliateLink());
        self::assertStringNotContainsString('&amp;', $offer->affiliateLink());
    }

    public function testEmptyCategoryAndDecimalPrice(): void
    {
        $offer = self::offer('7300392645675467000');

        self::assertNull($offer->categoryId());
        self::assertSame('1999.99', $offer->price()->amount());
        self::assertNull($offer->oldPrice());
    }

    public function testZeroPriceModelAndMarkdown(): void
    {
        $offer = self::offer('13547762558345343000');

        self::assertTrue($offer->price()->isZero());
        self::assertTrue($offer->charge()?->isZero());
        self::assertSame('DM-UDC045Z/DBF', $offer->model());
        self::assertStringStartsWith('**Dantex (Дантекс) DM-UDC045Z/DBF**', (string) $offer->description());
        self::assertStringContainsString("\n", (string) $offer->description());
        self::assertStringNotContainsString("\r", (string) $offer->description());
    }

    public function testAliExpressOffer(): void
    {
        $offer = self::offer('7273985276702969000');

        self::assertNull($offer->productUrl());
        self::assertStringNotContainsString('erid=', $offer->affiliateLink());
        self::assertNull($offer->vendor());
        self::assertSame('Реклама. Рекламодатель http://aliexpress.com/.', $offer->adMarking());
        self::assertSame('4682.38', $offer->oldPrice()?->amount());
    }

    public function testUnavailableOffer(): void
    {
        self::assertFalse(self::offer('7586175759118006000')->isAvailable());
    }

    public function testHtmlEntitiesAreDecoded(): void
    {
        self::assertSame('Джеггинсы "Анабель"', self::offer('3377556719066259000')->name());

        $description = (string) self::offer('2421799661623834600')->description();
        self::assertStringNotContainsString('&nbsp;', $description);
        self::assertStringContainsString("\u{00A0}", $description);
    }

    public function testNonBreakingSpacesAreTrimmed(): void
    {
        $offer = self::single(self::offerXml([], [
            'name' => '<![CDATA[&nbsp;Платье&nbsp;]]>',
            'description' => '<![CDATA[&nbsp;]]>',
            'vendor' => '<![CDATA[ &nbsp;Zarina&nbsp;Шоп&nbsp; ]]>',
        ]));

        self::assertSame('Платье', $offer->name());
        self::assertNull($offer->description());
        self::assertSame("Zarina\u{00A0}Шоп", $offer->vendor(), 'внутри текста неразрывный пробел сохраняется');
    }

    public function testRawAmpersandInCdataIsKept(): void
    {
        // синтетика: в CDATA сырой «&» рядом с сущностью
        $offer = self::single('<offer merchant_id="1" id="9"><price>10</price><currencyId>RUR</currencyId>'
            . '<name><![CDATA[Tom & Jerry &quot;Классика&quot;]]></name><url>https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1</url></offer>');

        self::assertSame('Tom & Jerry "Классика"', $offer->name());
        self::assertTrue($offer->isAvailable(), 'без атрибута available — доступен');
        self::assertNull($offer->article());
        self::assertNull($offer->categoryId());
    }

    public function testEmptyResultOnFirstPageHasZeroTotal(): void
    {
        $empty = Fixtures::read('search/search-empty.xml');

        self::assertSame(0, (new OfferXmlParser())->parse($empty, new SearchCriteria())->total());
        self::assertNull((new OfferXmlParser())->parse($empty, new SearchCriteria(page: 3))->total());
        self::assertTrue((new OfferXmlParser())->parse($empty, new SearchCriteria())->isEmpty());
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function totals(): iterable
    {
        yield 'неизвестно при непустой выдаче' => ['1000000', null];
        yield 'точное число' => ['5322', 5322];
        yield 'не число' => ['abc', null];
    }

    #[DataProvider('totals')]
    public function testTotal(string $documentsNumber, ?int $expected): void
    {
        $xml = sprintf(self::HEAD, $documentsNumber) . self::VALID . '</offers></yml_catalog>';

        self::assertSame($expected, (new OfferXmlParser())->parse($xml, new SearchCriteria())->total());
    }

    public function testMissingInfoMeansUnknownTotal(): void
    {
        $xml = '<yml_catalog><offers>' . self::VALID . '</offers></yml_catalog>';

        self::assertNull((new OfferXmlParser())->parse($xml, new SearchCriteria())->total());
    }

    #[DataProvider('brokenOffers')]
    public function testBrokenOfferIsSkipped(string $offer, string $field): void
    {
        $xml = sprintf(self::HEAD, '2') . $offer . self::VALID . '</offers></yml_catalog>';

        $result = (new OfferXmlParser())->parse($xml, new SearchCriteria());

        self::assertSame(['9'], array_map(static fn (Offer $o): string => $o->id(), $result->offers()));
        self::assertCount(1, $result->skipped());
        self::assertStringContainsString($field, $result->skipped()[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenOffers(): iterable
    {
        yield 'нет id' => [self::offerXml(['id' => null]), 'id'];
        yield 'id с буквами' => [self::offerXml(['id' => 'abc']), 'id'];
        yield 'нет merchant_id' => [self::offerXml(['merchant_id' => null]), 'merchant_id'];
        yield 'merchant_id ноль' => [self::offerXml(['merchant_id' => '0']), 'merchant_id'];
        yield 'merchant_id атрибута и элемента различаются' => [self::offerXml([], ['merchant_id' => '2']), 'merchant_id'];
        yield 'нет name' => [self::offerXml([], ['name' => null]), 'name'];
        yield 'пустой name' => [self::offerXml([], ['name' => '<![CDATA[]]>']), 'name'];
        yield 'name из &nbsp;' => [self::offerXml([], ['name' => '<![CDATA[&nbsp; &nbsp;]]>']), 'Пустое название'];
        yield 'нет price' => [self::offerXml([], ['price' => null]), 'price'];
        yield 'пустая price' => [self::offerXml([], ['price' => '']), 'price'];
        yield 'price с запятой' => [self::offerXml([], ['price' => '1,5']), '1,5'];
        yield 'отрицательная price' => [self::offerXml([], ['price' => '-1']), '-1'];
        yield 'oldprice не число' => [self::offerXml([], ['oldprice' => 'x']), 'oldprice'];
        yield 'charge не число' => [self::offerXml([], ['charge' => 'x']), 'charge'];
        yield 'нет currencyId' => [self::offerXml([], ['currencyId' => null]), 'currencyId'];
        yield 'currencyId строчными' => [self::offerXml([], ['currencyId' => 'rub']), 'rub'];
        yield 'нет url' => [self::offerXml([], ['url' => null]), 'url'];
        yield 'url не http' => [self::offerXml([], ['url' => 'ftp://x']), 'ссылка'];
        yield 'категория с буквами' => [self::offerXml(['gs_category_id' => 'abc']), 'gs_category_id'];
        yield 'категория ноль' => [self::offerXml(['gs_category_id' => '0']), 'gs_category_id'];
        yield 'available не булев' => [self::offerXml(['available' => 'maybe']), 'available'];
    }

    /**
     * @param array<string, string|null> $attributes значение null — атрибута нет
     * @param array<string, string|null> $elements   значение null — элемента нет
     */
    private static function offerXml(array $attributes = [], array $elements = []): string
    {
        $attributes += ['merchant_id' => '1', 'id' => '7'];
        $elements += ['price' => '10', 'currencyId' => 'RUR', 'name' => '<![CDATA[x]]>', 'url' => 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1'];
        $xml = '<offer';
        foreach ($attributes as $name => $value) {
            $xml .= $value === null ? '' : sprintf(' %s="%s"', $name, $value);
        }
        $xml .= '>';
        foreach ($elements as $name => $value) {
            $xml .= $value === null ? '' : sprintf('<%1$s>%2$s</%1$s>', $name, $value);
        }

        return $xml . '</offer>';
    }

    public function testAllOffersBrokenIsBrokenDocument(): void
    {
        $xml = sprintf(self::HEAD, '2') . '<offer id="1"/><offer id="2"/></offers></yml_catalog>';

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('ни одна запись не разобрана');

        (new OfferXmlParser())->parse($xml, new SearchCriteria());
    }

    public function testUnknownElementsAndAttributesAreIgnored(): void
    {
        $offer = self::single('<offer gs_product_key="x" merchant_id="1" id="9" new="y"><param name="Цвет">Красный</param><categoryId>5</categoryId>'
            . '<price>10</price><currencyId>RUR</currencyId><name>x</name><url>https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1</url></offer>');

        self::assertSame('9', $offer->id());
    }

    public function testWindows1251Document(): void
    {
        // синтетика: «Всё» в windows-1251
        $xml = "<?xml version=\"1.0\" encoding=\"windows-1251\"?><yml_catalog><offers><offer merchant_id=\"1\" id=\"9\"><price>1</price>"
            . "<currencyId>RUR</currencyId><name>\xc2\xf1\xb8</name><url>https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1</url></offer></offers></yml_catalog>";

        self::assertSame('Всё', (new OfferXmlParser())->parse($xml, new SearchCriteria())->offers()[0]->name());
    }

    public function testNotSearchDocument(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Ответ поиска');

        (new OfferXmlParser())->parse('This affiliate token does not exists', new SearchCriteria());
    }

    public function testResultKeepsCriteria(): void
    {
        $criteria = new SearchCriteria(query: 'x', limit: 8);

        self::assertSame($criteria, (new OfferXmlParser())->parse(Fixtures::read('search/search.xml'), $criteria)->criteria());
    }

    private static function fixture(): SearchResult
    {
        return (new OfferXmlParser())->parse(Fixtures::read('search/search.xml'), new SearchCriteria());
    }

    private static function offer(string $id): Offer
    {
        foreach (self::fixture()->offers() as $offer) {
            if ($offer->id() === $id) {
                return $offer;
            }
        }

        throw new \LogicException('Нет оффера ' . $id);
    }

    private static function single(string $offer): Offer
    {
        return (new OfferXmlParser())->parse('<yml_catalog><offers>' . $offer . '</offers></yml_catalog>', new SearchCriteria())->offers()[0];
    }
}
