<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Yml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\OfferParam;
use Webreboot\GdeSlon\Domain\Catalog\Picture;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\OfferXmlParser;
use Webreboot\GdeSlon\Infrastructure\Yml\OfferMapper;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class OfferMapperTest extends TestCase
{
    public function testFeedOfferHasAllFields(): void
    {
        $offer = self::feedOffer('3080332');

        self::assertSame('3377556719066258936', $offer->id());
        self::assertSame(86276, $offer->merchantId()->value());
        self::assertSame('86276:3080332', (string) $offer->key());
        self::assertSame('Джеггинсы "Анабель"', $offer->name());
        self::assertSame('1499.00 RUB', (string) $offer->price());
        self::assertSame('3599.00 RUB', (string) $offer->oldPrice());
        self::assertNull($offer->charge());
        self::assertTrue($offer->isAvailable());
        self::assertSame(1144, $offer->categoryId()?->value());
        self::assertSame('ANIKO', $offer->vendor());
        self::assertNull($offer->model());
        self::assertNull($offer->description());
        self::assertSame('https://imgng.gdeslon.ru/commodities/406574050/pictures/921264f33692bf4fe964104289a5faf7/big.jpg', $offer->picture());
        self::assertSame('https://imgng.gdeslon.ru/commodities/406574050/pictures/921264f33692bf4fe964104289a5faf7/small.jpg', $offer->thumbnail());
        self::assertSame('https://www.leomax.ru/upload/iblock/d29/d29279420bd9c00bf850bd3bfc831dba.jpg', $offer->originalPicture());
        self::assertSame('https://www.leomax.ru/goods/dzhegginsy_anabel-double-1/', $offer->productUrl());
        self::assertStringStartsWith('https://xf.gdeslon.ru/cm/0a1b2c3d4e/?mid=86276&goto=', $offer->affiliateLink());
        self::assertSame('Kra23x72C', $offer->erid());
        self::assertSame(
            [['Сезон', 'Демисезон', null], ['Цвет', 'Серый', null], ['Размер', '50-52', null], ['Тип', 'джеггинсы', null]],
            array_map(static fn (OfferParam $p): array => [$p->name(), $p->value(), $p->unit()], $offer->params()),
        );
        self::assertSame('Серый', $offer->param('цвет'));
        self::assertCount(3, $offer->pictures());
        self::assertContainsOnlyInstancesOf(Picture::class, $offer->pictures());
    }

    public function testFeedOfferKeyMatchesSearchOfferKeyDespiteRoundedSearchId(): void
    {
        $search = (new OfferXmlParser())->parse(Fixtures::read('feeds/search-article.xml'), new SearchCriteria())->offers()[0];
        $feed = self::feedOffer('3080332');

        $searchKey = $search->key();
        self::assertNotNull($searchKey);
        self::assertTrue($feed->key()?->equals($searchKey));
        self::assertNotSame($search->id(), $feed->id());
        self::assertSame($search->name(), $feed->name());
        self::assertSame($search->picture(), $feed->picture());
    }

    public function testEmptyOldPriceIsNull(): void
    {
        self::assertNull(self::feedOffer('3086421')->oldPrice());
    }

    public function testIdBeyondIntegerRangeStaysString(): void
    {
        self::assertSame('11579145993250928818', self::feedOffer('22756')->id());
    }

    public function testOfferWithoutParams(): void
    {
        $offer = self::feedOffer('10763691');

        self::assertSame([], $offer->params());
        self::assertNull($offer->param('Цвет'));
    }

    public function testSizeListStaysRaw(): void
    {
        self::assertSame('ONESIZERU NoneRU', self::feedOffer('238352')->param('Размер'));
        self::assertSame(['40RU'], self::feedOffer('4690758')->paramValues('размер_ru'));
        self::assertSame('7,5UK', self::feedOffer('4690758')->param('Размер_UK'));
    }

    public function testDescriptionEntitiesAreDecoded(): void
    {
        $description = self::feedOffer('6479560')->description();

        self::assertNotNull($description);
        self::assertStringNotContainsString('&quot;', $description);
        self::assertStringContainsString('"', $description);
    }

    public function testNonNumericArticleKey(): void
    {
        self::assertSame('110385:ZR2610013308-2', (string) self::feedOffer('ZR2610013308-2')->key());
    }

    public function testEridFromMarkingOrLinkOrNone(): void
    {
        self::assertSame('2SDnjeHA3dF', self::feedOffer('6479560')->erid());
        self::assertNull(self::feedOffer('801479003')->erid());
    }

    public function testZeroPriceIsMappedForReaderToSkip(): void
    {
        self::assertTrue(self::feedOffer('4690374')->price()->isZero());
    }

    public function testEveryFixtureOfferIsMapped(): void
    {
        $offers = array_map(
            static fn (\SimpleXMLElement $offer): Offer => (new OfferMapper())->map($offer),
            self::fixtureOffers(),
        );

        self::assertCount(22, $offers);
        foreach ($offers as $offer) {
            self::assertNotNull($offer->key());
            self::assertNotNull($offer->categoryId());
            self::assertStringStartsWith('https://', (string) $offer->picture());
            self::assertStringStartsWith('https://', (string) $offer->thumbnail());
        }
    }

    public function testParamsWithoutNameOrValueAreDropped(): void
    {
        $offer = self::map(self::xml(params: '<param name="Цвет"><![CDATA[Серый]]></param><param><![CDATA[x]]></param>'
            . '<param name=" "><![CDATA[y]]></param><param name="Пол"><![CDATA[]]></param><param name="Размер" unit="RU">42</param>'));

        self::assertSame(
            [['Цвет', 'Серый', null], ['Размер', '42', 'RU']],
            array_map(static fn (OfferParam $p): array => [$p->name(), $p->value(), $p->unit()], $offer->params()),
        );
    }

    public function testParamEntitiesAreDecodedOnce(): void
    {
        $offer = self::map(self::xml(params: '<param name="&#x426;&#x432;&#x435;&#x442;"><![CDATA[&quot;Графит&quot; &amp;amp;]]></param>'));

        self::assertSame('"Графит" &amp;', $offer->param('Цвет'));
    }

    public function testProtocolRelativeOriginalPictureGetsHttps(): void
    {
        $offer = self::map(self::xml(original: '//cdn.example.ru/a.jpg'));

        self::assertSame('https://cdn.example.ru/a.jpg', $offer->originalPicture());
    }

    public function testInvalidPictureIsDroppedWithoutSkippingOffer(): void
    {
        $offer = self::map(self::xml(picture: 'abc'));

        self::assertNull($offer->picture());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenOffers(): iterable
    {
        yield 'gs_category_id ≠ categoryId' => [self::xml(categoryId: '1145'), 'gs_category_id (1144) и categoryId (1145) различаются'];
        yield 'нет url' => [self::xml(url: null), 'нет поля url'];
        yield 'нет price' => [self::xml(price: null), 'нет поля price'];
        yield 'нет name' => [self::xml(name: null), 'нет поля name'];
        yield 'цена с пробелом' => [self::xml(price: '1 499'), 'поле price'];
        yield 'цена с запятой' => [self::xml(price: '1499,00'), 'поле price'];
        yield 'неизвестная валюта' => [self::xml(currency: 'RU'), 'поле currencyId'];
        yield 'id не из цифр' => [str_replace('id="1"', 'id="A1"', self::xml()), 'атрибут id должен состоять из цифр'];
    }

    #[DataProvider('brokenOffers')]
    public function testBrokenOfferIsRejectedWithReason(string $xml, string $reason): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage($reason);

        self::map($xml);
    }

    private static function feedOffer(string $article): Offer
    {
        foreach (self::fixtureOffers() as $offer) {
            if ((string) $offer['article'] === $article) {
                return (new OfferMapper())->map($offer);
            }
        }

        self::fail('Нет оффера с article ' . $article);
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private static function fixtureOffers(): array
    {
        $feed = simplexml_load_string(Fixtures::read('feeds/feed.xml'), null, LIBXML_NONET);
        self::assertNotFalse($feed);

        $offers = [];
        foreach ($feed->shop->offers->offer as $offer) {
            $offers[] = $offer;
        }

        return $offers;
    }

    private static function map(string $xml): Offer
    {
        $offer = simplexml_load_string($xml, null, LIBXML_NONET);
        self::assertNotFalse($offer);

        return (new OfferMapper())->map($offer);
    }

    private static function xml(
        ?string $name = 'Платье',
        ?string $price = '1499.00',
        ?string $url = 'https://xf.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&amp;goto=https%3A%2F%2Fshop.ru%2F',
        string $currency = 'RUR',
        string $categoryId = '1144',
        string $picture = '//imgng.gdeslon.ru/a/big.jpg',
        string $original = 'https://shop.ru/a.jpg',
        string $params = '',
    ): string {
        return '<offer id="1" merchant_id="1" gs_product_key="" article="a1" gs_category_id="1144">'
            . "<picture>$picture</picture><original_picture>$original</original_picture>"
            . ($name === null ? '' : "<name><![CDATA[$name]]></name>")
            . '<oldprice/>' . $params
            . ($url === null ? '' : "<url>$url</url>")
            . "<currencyId>$currency</currencyId><categoryId>$categoryId</categoryId>"
            . ($price === null ? '' : "<price>$price</price>")
            . '</offer>';
    }
}
