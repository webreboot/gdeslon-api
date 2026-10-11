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
            ['14367733380732236000', '7300392645675467000', '725748519890628700', '7273985276702969000', '3377556719066259000', '2421799661623834600', '7586175759118006000'],
            array_map(static fn (Offer $o): string => $o->id(), $result->offers()),
        );
        self::assertCount(1, $result->skipped());
        self::assertStringContainsString('оффер «13547762558345343000»', $result->skipped()[0]);
        self::assertStringContainsString('цена 0', $result->skipped()[0]);
        self::assertSame(5322, $result->total());
    }

    public function testParsesLiveEdgeFixture(): void
    {
        $result = self::edge();

        self::assertSame(
            ['13876006777101574000', '3394224451100314600', '2026950605734530000', '1732069055518743000', '10557192786987480000', '1074890176067626600'],
            array_map(static fn (Offer $o): string => $o->id(), $result->offers()),
        );
        self::assertCount(1, $result->skipped());
        self::assertStringContainsString('оффер «3234975053357548000»', $result->skipped()[0]);
        self::assertStringContainsString('цена 0', $result->skipped()[0]);
        self::assertNull($result->total());
        foreach ($result->offers() as $offer) {
            self::assertSame('RUB', $offer->price()->currency());
        }
    }

    public function testDescriptionDoubleEscapedOnceFromLiveFixture(): void
    {
        $description = (string) self::edgeOffer('13876006777101574000')->description();

        self::assertStringContainsString('винной кислоты &mdash; это шипучая кислота', $description);
        self::assertStringNotContainsString('&amp;', $description);
        self::assertStringNotContainsString('—', $description);
    }

    public function testLiveFixtureEntitiesDecoded(): void
    {
        $soap = (string) self::edgeOffer('3394224451100314600')->description();
        self::assertStringStartsWith('• 100% французский бренд, 100% сделано во Франции<br/>• Независимый производитель', $soap);
        self::assertStringNotContainsString('&lt;', $soap);

        $mixer = (string) self::edgeOffer('2026950605734530000')->description();
        self::assertStringContainsString('керамический вентиль 1/2"", 180°', $mixer);
        self::assertStringNotContainsString('&quot;', $mixer);
    }

    public function testAdMarkingEntitiesDecoded(): void
    {
        $offer = self::single(self::offerXml([], [
            'tagging_ads' => '<info><![CDATA[Реклама. Рекламодатель ООО &quot;Ромашка&quot; &laquo;Сад&raquo; ИНН 7700000000]]></info>',
        ]));

        self::assertSame('Реклама. Рекламодатель ООО "Ромашка" «Сад» ИНН 7700000000', $offer->adMarking());
    }

    public function testPlainAdMarkingNotDecodedAgain(): void
    {
        $offer = self::single(self::offerXml([], ['tagging_ads' => '<info>ООО &amp;quot;Ромашка&amp;quot;</info>']));

        self::assertSame('ООО &quot;Ромашка&quot;', $offer->adMarking());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function currencies(): iterable
    {
        yield 'RUR' => ['RUR', 'RUB'];
        yield 'rur' => ['rur', 'RUB'];
        yield 'RUR с пробелами' => [' RUR ', 'RUB'];
        yield 'rub' => ['rub', 'RUB'];
        yield 'USD' => ['USD', 'USD'];
    }

    #[DataProvider('currencies')]
    public function testCurrencyIsNormalized(string $currencyId, string $expected): void
    {
        $offer = self::single(self::offerXml([], ['currencyId' => $currencyId, 'oldprice' => '20', 'charge' => '1.5']));

        self::assertSame($expected, $offer->price()->currency());
        self::assertSame($expected, $offer->oldPrice()?->currency());
        self::assertSame($expected, $offer->charge()?->currency());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zeroPrices(): iterable
    {
        yield '0' => ['0'];
        yield '0.0' => ['0.0'];
        yield '0.00' => ['0.00'];
    }

    #[DataProvider('zeroPrices')]
    public function testZeroPriceOfferIsSkipped(string $price): void
    {
        $xml = sprintf(self::HEAD, '2') . self::offerXml([], ['price' => $price, 'charge' => '13.65']) . self::VALID . '</offers></yml_catalog>';

        $result = (new OfferXmlParser())->parse($xml, new SearchCriteria());

        self::assertSame(['9'], array_map(static fn (Offer $o): string => $o->id(), $result->offers()));
        self::assertSame(['оффер «7»: цена 0, оффер пропущен'], $result->skipped());
    }

    public function testPageOfZeroPricesIsEmptyNotBroken(): void
    {
        $xml = sprintf(self::HEAD, '82') . self::offerXml(['id' => '1'], ['price' => '0']) . self::offerXml(['id' => '2'], ['price' => '0.00'])
            . '</offers></yml_catalog>';

        $result = (new OfferXmlParser())->parse($xml, new SearchCriteria());

        self::assertSame([], $result->offers());
        self::assertCount(2, $result->skipped());
        self::assertSame(82, $result->total());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zeroCharges(): iterable
    {
        yield '0' => ['0'];
        yield '0.00' => ['0.00'];
    }

    #[DataProvider('zeroCharges')]
    public function testZeroChargeBecomesNull(string $charge): void
    {
        $offer = self::single(self::offerXml([], ['charge' => $charge]));

        self::assertNull($offer->charge());
        self::assertSame('10 RUB', (string) $offer->price());
    }

    public function testInvalidOptionalUrlsBecomeNull(): void
    {
        $offer = self::single(self::offerXml([], [
            'picture' => 'javascript:alert(1)',
            'thumbnail' => '/img/small.jpg',
            'original_picture' => 'ftp://cdn.example.ru/a.png',
            'destination-url-do-not-send-traffic' => '<![CDATA[data:text/html,x]]>',
        ]));

        self::assertNull($offer->picture());
        self::assertNull($offer->thumbnail());
        self::assertNull($offer->originalPicture());
        self::assertNull($offer->productUrl());
        self::assertSame([], $offer->pictureCandidates());
    }

    public function testEmptyPictureElements(): void
    {
        $offer = self::edgeOffer('1732069055518743000');

        self::assertNull($offer->picture());
        self::assertNull($offer->thumbnail());
        self::assertNull($offer->originalPicture());
        self::assertSame([], $offer->pictureCandidates());
        self::assertNull($offer->charge());
        self::assertSame('https://www.tutu.ru/', $offer->productUrl());
    }

    public function testHttpOriginalPictureKept(): void
    {
        $offer = self::edgeOffer('10557192786987480000');

        self::assertSame('http://globaldrive.ru/upload/iblock/52a/ra9ou5fpl3qw06zreoe1394subz5tghx.jpg', $offer->originalPicture());
        self::assertSame([
            'http://globaldrive.ru/upload/iblock/52a/ra9ou5fpl3qw06zreoe1394subz5tghx.jpg',
            'https://imgng.gdeslon.ru/commodities/504923255/pictures/f4b01e53c014813d697bb6f544f7ea4d/big.jpg',
            'https://imgng.gdeslon.ru/commodities/504923255/pictures/f4b01e53c014813d697bb6f544f7ea4d/small.jpg',
        ], $offer->pictureCandidates());
        self::assertSame('2820.72 RUB', (string) $offer->charge());
    }

    public function testOfferWithoutOriginalPicture(): void
    {
        $offer = self::edgeOffer('1074890176067626600');

        self::assertNull($offer->originalPicture());
        self::assertNull($offer->thumbnail());
        self::assertSame(['https://imgng.gdeslon.ru/commodities/477057687/pictures/None/big.jpg'], $offer->pictureCandidates());
    }

    public function testBasicOffer(): void
    {
        $offer = self::offer('14367733380732236000');

        self::assertSame(107054, $offer->merchantId()->value());
        self::assertSame('578237', $offer->article());
        self::assertSame(26, $offer->categoryId()?->value());
        self::assertSame('100 RUB', (string) $offer->price());
        self::assertNull($offer->oldPrice());
        self::assertSame('3.64 RUB', (string) $offer->charge());
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

    public function testMarkdownDescriptionKeepsLineFeeds(): void
    {
        $offer = self::single(self::offerXml([], [
            'description' => "<![CDATA[**Dantex (Дантекс) DM-UDC045Z/DBF**\r\n\r\n- мощность &mdash; 4,5 кВт\r\n- класс A]]>",
            'model' => '<![CDATA[DM-UDC045Z/DBF]]>',
        ]));

        self::assertSame('DM-UDC045Z/DBF', $offer->model());
        self::assertSame("**Dantex (Дантекс) DM-UDC045Z/DBF**\n\n- мощность — 4,5 кВт\n- класс A", $offer->description());
    }

    public function testAliExpressOffer(): void
    {
        $offer = self::offer('7273985276702969000');

        self::assertNull($offer->productUrl());
        self::assertStringNotContainsString('erid=', $offer->affiliateLink());
        self::assertNull($offer->vendor());
        self::assertSame('Реклама. Рекламодатель http://aliexpress.com/.', $offer->adMarking());
        self::assertSame('4682.38', $offer->oldPrice()?->amount());
        self::assertNull($offer->erid());
        self::assertSame(82012, $offer->link()->merchantId()?->value());
        self::assertSame('82012:1005006482498878', (string) $offer->key());
    }

    public function testEridAndKeyFromRealFixture(): void
    {
        $offer = self::offer('14367733380732236000');

        self::assertSame('2SDnjcZqvUG', $offer->erid());
        self::assertSame('2SDnjcZqvUG', $offer->link()->erid());
        self::assertSame(107054, $offer->link()->merchantId()?->value());
        self::assertSame('107054:578237', (string) $offer->key());
        self::assertSame($offer->affiliateLink() . '&sub_id=tg_channel-42', $offer->link()->withSubId('tg_channel-42')->url());
        self::assertSame('Kra23x72C', self::offer('3377556719066259000')->erid());

        $sameArticle = self::offer('7586175759118006000');
        self::assertSame('113068:578237', (string) $sameArticle->key());
        self::assertFalse($sameArticle->key()?->equals($offer->key() ?? throw new \LogicException()));

        foreach ([...self::fixture()->offers(), ...self::edge()->offers()] as $each) {
            self::assertSame($each->merchantId()->value(), $each->link()->merchantId()?->value(), $each->id());
            if ($each->erid() !== null && $each->link()->erid() !== null) {
                self::assertSame($each->link()->erid(), $each->erid(), $each->id());
            }
        }
    }

    public function testOfferWithoutEridAnywhere(): void
    {
        $offer = self::edgeOffer('1732069055518743000');

        self::assertNull($offer->erid());
        self::assertNull($offer->link()->erid());
        self::assertStringEndsWith('https://www.tutu.ru', (string) $offer->adMarking());
        self::assertSame('81319:002', (string) $offer->key());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linksWithWhitespace(): iterable
    {
        yield 'пробел в goto' => ['https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&amp;goto=https://x.ru/a b'];
        yield 'табуляция' => ["https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1\t&amp;erid=2SDnjTEST001"];
        yield 'перевод строки сущностью' => ['https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&#10;&amp;erid=2SDnjTEST001'];
    }

    #[DataProvider('linksWithWhitespace')]
    public function testLinkWithWhitespaceIsSkipped(string $url): void
    {
        $xml = sprintf(self::HEAD, '2') . self::offerXml([], ['url' => $url]) . self::VALID . '</offers></yml_catalog>';

        $result = (new OfferXmlParser())->parse($xml, new SearchCriteria());

        self::assertSame(['9'], array_map(static fn (Offer $o): string => $o->id(), $result->offers()));
        self::assertCount(1, $result->skipped());
        self::assertStringContainsString('оффер «7»', $result->skipped()[0]);
        self::assertStringContainsString('ссылка', $result->skipped()[0]);
        self::assertStringNotContainsString('0a1b2c3d4e', $result->skipped()[0]);
    }

    public function testLinkWithSpacesAroundIsTrimmed(): void
    {
        $offer = self::single(self::offerXml([], ['url' => " \n https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1 \n"]));

        self::assertSame('https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1', $offer->affiliateLink());
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
        yield 'currencyId из двух букв' => [self::offerXml([], ['currencyId' => 'RU']), 'currencyId'];
        yield 'currencyId кириллицей' => [self::offerXml([], ['currencyId' => 'РУБ']), 'currencyId'];
        yield 'currencyId из четырёх букв' => [self::offerXml([], ['currencyId' => 'RUBL']), 'currencyId'];
        yield 'пустой currencyId' => [self::offerXml([], ['currencyId' => ' ']), 'currencyId'];
        yield 'нет url' => [self::offerXml([], ['url' => null]), 'url'];
        yield 'url не http' => [self::offerXml([], ['url' => 'ftp://x']), 'ссылка'];
        yield 'категория с буквами' => [self::offerXml(['gs_category_id' => 'abc']), 'gs_category_id'];
        yield 'категория ноль' => [self::offerXml(['gs_category_id' => '0']), 'gs_category_id'];
        yield 'available не булев' => [self::offerXml(['available' => 'maybe']), 'available'];
    }

    /**
     * @param array<string, string|null> $attributes
     * @param array<string, string|null> $elements
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

    private static function edge(): SearchResult
    {
        return (new OfferXmlParser())->parse(Fixtures::read('search/search-edge.xml'), new SearchCriteria());
    }

    private static function edgeOffer(string $id): Offer
    {
        foreach (self::edge()->offers() as $offer) {
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
