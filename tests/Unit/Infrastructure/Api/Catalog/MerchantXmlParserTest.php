<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTariff;
use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Domain\Catalog\MerchantCategory;
use Webreboot\GdeSlon\Domain\Catalog\RateType;
use Webreboot\GdeSlon\Domain\Catalog\Tariff;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\MerchantXmlParser;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class MerchantXmlParserTest extends TestCase
{
    public function testParsesRealFixtureInOrder(): void
    {
        $list = self::fixture();

        self::assertSame([], $list->skipped());

        self::assertSame(
            [105263, 117999, 115651, 112032, 110385, 112387, 82012, 101124, 106797],
            array_map(static fn (Merchant $m): int => $m->id()->value(), $list->all()),
        );
    }

    public function testMinimalRealMerchant(): void
    {
        $m = self::fixture()->get(105263);

        self::assertSame('superstep.ru', $m->name());
        self::assertSame('https://superstep.ru/', $m->url());
        self::assertSame('superstep.ru', $m->domain());
        self::assertSame('Первый сникер-проект без шаблонов.', $m->shortDescription());
        self::assertSame('https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=Kra23dyAk&mid=105263', $m->affiliateLink());
        self::assertSame('10,3%', $m->commissionSummary());
        self::assertSame('Физическое лицо', $m->kind());
        self::assertSame('ru', $m->country());
        self::assertFalse($m->isGreen());
        self::assertSame('https://cdn.gdeslon.ru/uploads/users/105263/logos/big.png?1788176999', $m->logoUrl());
        self::assertSame([[5, 'Одежда и обувь']], array_map(static fn (MerchantCategory $c): array => [$c->id(), $c->name()], $m->categories()));
        self::assertCount(23, $m->trafficTypes());
        self::assertSame('Контекстная реклама', $m->trafficTypes()[0]->name());
        self::assertFalse($m->isTrafficTypeAllowed('Контекстная реклама'));
        self::assertCount(17, $m->allowedTrafficTypes());
        self::assertSame([['1388', 'Оплаченный заказ', RateType::Percent, '10.3', []]], self::tariffs($m));
        self::assertSame([], $m->categoryTariffs());
        self::assertStringStartsWith('Реклама. Рекламодатель ООО «ИНТЕРМОДЕ» ИНН 7709900734', (string) $m->adMarking());
    }

    public function testMarkdownTextWithoutCarriageReturns(): void
    {
        $description = self::fixture()->get(105263)->description();

        self::assertStringStartsWith('**SuperStep** – это первый сникер-проект без шаблонов.', $description);
        self::assertStringNotContainsString("\r", $description);
        self::assertStringContainsString("\n", $description);
    }

    public function testEmptyCommissionSummaryAndFixedTariffs(): void
    {
        $m = self::fixture()->get(117999);

        self::assertNull($m->commissionSummary());
        self::assertSame([
            ['2777', 'Новый пользователь, оформивший подписку', RateType::Fixed, '650.0', []],
            ['2778', 'Новый пользователь, оформивший подписку, тип трафика: «ретаргетинг»', RateType::Fixed, '600.0', []],
        ], self::tariffs($m));
    }

    public function testEntitiesAreDecoded(): void
    {
        $m = self::fixture()->get(112032);

        self::assertSame('5ka.ru (Android & IOS)', $m->name());
        self::assertSame('Заказ от "Новичок 365"', $m->tariffs()[2]->title());
    }

    public function testTrafficCategoriesOfTariffs(): void
    {
        self::assertSame(
            [[], [], ['coupons', 'promocodes'], ['cashback']],
            array_map(static fn (Tariff $t): array => $t->trafficCategories(), self::fixture()->get(110385)->tariffs()),
        );
    }

    public function testMerchantWithoutTariffsAndAdMarking(): void
    {
        $m = self::fixture()->get(82012);

        self::assertSame([], $m->tariffs());
        self::assertNull($m->adMarking());
        self::assertSame('http://aliexpress.com/', $m->url());
        self::assertSame('https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=&mid=82012', $m->affiliateLink());
        self::assertSame('0,75% - 69%', $m->commissionSummary());
    }

    public function testMerchantWithoutAffiliateLink(): void
    {
        $m = self::fixture()->get(101124);

        self::assertNull($m->affiliateLink());
        self::assertSame([], $m->tariffs());
    }

    public function testCategoryTariffsAndProductCategories(): void
    {
        $m = self::fixture()->get(106797);

        self::assertSame(
            [[1000, 'iPad Pro M4 (2024)', true, '2.93'], [1001, 'iPad Air M3 (2025)', true, '2.93'], [1002, 'MacBook Pro M5', true, '2.93']],
            array_map(static fn (CategoryTariff $t): array => [$t->merchantCategoryId(), $t->name(), $t->isPercent(), $t->rate()], $m->categoryTariffs()),
        );
        $byCategory = $m->tariffs()[8];
        self::assertSame('fac224151ea607cf0b05c247ee1439cb', $byCategory->id());
        self::assertNull($byCategory->title());
        self::assertSame(RateType::Percent, $byCategory->rateType());
        self::assertSame('2.93', $byCategory->rate());
        self::assertSame(
            ['Наушники', 'Apple', 'Приставки для ТВ', 'Вертикальные пылесосы', 'Фены и стайлеры', 'Акустика', 'Смартфоны', 'Планшеты', 'Часы и трекеры', 'Игровые консоли'],
            $byCategory->productCategories(),
        );
        self::assertCount(13, $m->tariffs());
        self::assertSame([], $m->allowedTrafficTypes());
    }

    public function testCategoryTariffWithoutName(): void
    {
        // форма из реального ответа (магазин 100880 wishmaster.me): name="" у одного tariff-category из 212
        $m = (new MerchantXmlParser())->parse(self::shops(
            '<shop><id>100880</id><name>wishmaster.me</name><url>https://wishmaster.me/</url><tariffs>'
            . '<tariff-category name="Категория 2" category_id="1" is_percent="true">1.95</tariff-category>'
            . '<tariff-category name="" category_id="6" is_percent="true">1.07</tariff-category>'
            . '</tariffs></shop>',
        ))->get(100880);

        self::assertSame(
            [[1, 'Категория 2', '1.95'], [6, null, '1.07']],
            array_map(static fn (CategoryTariff $t): array => [$t->merchantCategoryId(), $t->name(), $t->rate()], $m->categoryTariffs()),
        );
    }

    public function testPublicCatalogHasNoAffiliateLinks(): void
    {
        $list = (new MerchantXmlParser())->parse(Fixtures::read('merchants/shops-public.xml'));

        self::assertCount(2, $list);
        foreach ($list as $merchant) {
            self::assertNull($merchant->affiliateLink());
        }
    }

    #[DataProvider('emptyDocuments')]
    public function testEmptyCatalog(string $xml): void
    {
        self::assertCount(0, (new MerchantXmlParser())->parse($xml));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyDocuments(): iterable
    {
        yield 'самозакрытый' => ['<?xml version="1.0" encoding="UTF-8"?><shops/>'];
        yield 'пустой' => ['<shops></shops>'];
        yield 'перевод строки' => ["<shops>\n</shops>\n"];
    }

    public function testMinimalRecordAndUnknownElements(): void
    {
        // синтетика
        $m = (new MerchantXmlParser())->parse(self::shops(
            '<shop><id>7</id><name>x.ru</name><url>https://x.ru/</url><new-field>?</new-field>'
            . '<tariffs><tariff id="1" title="t" rate_type="fixed" extra="y">5.0</tariff></tariffs></shop>',
        ))->get(7);

        self::assertSame('x.ru', $m->name());
        self::assertSame('', $m->description());
        self::assertSame([], $m->categories());
        self::assertSame([], $m->trafficTypes());
        self::assertFalse($m->isGreen());
        self::assertSame([['1', 't', RateType::Fixed, '5.0', []]], self::tariffs($m));
    }

    #[DataProvider('brokenRecords')]
    public function testBrokenMerchantIsSkipped(string $shop, string $field): void
    {
        // синтетика: битый магазин пропускается, остальные загружаются
        $valid = '<shop><id>8</id><name>ok.ru</name><url>https://ok.ru/</url></shop>';

        $list = (new MerchantXmlParser())->parse(self::shops($shop . $valid));

        self::assertSame([8], array_map(static fn (Merchant $m): int => $m->id()->value(), $list->all()));
        self::assertCount(1, $list->skipped());
        self::assertStringContainsString($field, $list->skipped()[0]);
    }

    public function testDocumentWithoutSingleParsedRecordIsBroken(): void
    {
        // систематическое изменение формата (например, переименованное поле) — не «пустой каталог»
        $broken = '<shop><id>7</id><name>x</name><site>https://x.ru/</site></shop><shop><id>8</id><name>y</name></shop>';

        try {
            (new MerchantXmlParser())->parse(self::shops($broken));
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('ни одна запись не разобрана', $e->getMessage());
            self::assertStringContainsString('url', $e->getMessage());
        }
    }

    public function testDuplicateMerchantIsSkipped(): void
    {
        $shop = '<shop><id>7</id><name>x</name><url>https://x.ru/</url></shop>';

        $list = (new MerchantXmlParser())->parse(self::shops($shop . $shop));

        self::assertCount(1, $list);
        self::assertCount(1, $list->skipped());
        self::assertStringContainsString('7', $list->skipped()[0]);
    }

    public function testEmptyNamesOfNestedRecordsAreNotErrors(): void
    {
        // синтетика по образцу живой аномалии (пустой name у tariff-category магазина 100880)
        $m = (new MerchantXmlParser())->parse(self::shops(
            '<shop><id>7</id><name>x</name><url>https://x.ru/</url>'
            . '<categories><category><id>5</id><name></name></category></categories>'
            . '<traffic-types><traffic-type><name></name><allowed>yes</allowed></traffic-type>'
            . '<traffic-type><name>Cashback</name><allowed>no</allowed></traffic-type></traffic-types></shop>',
        ))->get(7);

        self::assertSame(5, $m->categories()[0]->id());
        self::assertNull($m->categories()[0]->name());
        self::assertSame(['Cashback'], $m->forbiddenTrafficTypes());
        self::assertCount(1, $m->trafficTypes(), 'тип трафика без названия отбрасывается');
    }

    public function testRequiredFieldsAreTrimmed(): void
    {
        $list = (new MerchantXmlParser())->parse(self::shops(
            "<shop><id> 7 </id><name> x.ru </name><url><![CDATA[ https://x.ru/]]></url></shop>"
            . "<shop><id>8</id><name>y</name><url>https://y.ru/\n</url></shop>",
        ));

        self::assertSame('https://x.ru/', $list->get(7)->url());
        self::assertSame('x.ru', $list->get(7)->name());
        self::assertSame('https://y.ru/', $list->get(8)->url());
        self::assertSame([], $list->skipped());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenRecords(): iterable
    {
        $base = static fn (string $extra = '', string $id = '<id>7</id>', string $name = '<name>x</name>', string $url = '<url>https://x.ru/</url>'): string
            => '<shop>' . $id . $name . $url . $extra . '</shop>';

        yield 'нет id' => [$base(id: ''), 'без id'];
        yield 'id ноль' => [$base(id: '<id>0</id>'), 'id'];
        yield 'id отрицательный' => [$base(id: '<id>-1</id>'), 'id'];
        yield 'id с буквами' => [$base(id: '<id>12a</id>'), 'id'];
        yield 'id пустой' => [$base(id: '<id></id>'), 'id'];
        yield 'нет name' => [$base(name: ''), 'name'];
        yield 'пустой name' => [$base(name: '<name> </name>'), 'name'];
        yield 'нет url' => [$base(url: ''), 'url'];
        yield 'is-green' => [$base('<is-green>maybe</is-green>'), 'is-green'];
        yield 'allowed' => [$base('<traffic-types><traffic-type><name>Cashback</name><allowed>maybe</allowed></traffic-type></traffic-types>'), 'allowed'];
        yield 'rate_type' => [$base('<tariffs><tariff id="1" rate_type="bonus">1.0</tariff></tariffs>'), 'rate_type'];
        yield 'ставка с запятой' => [$base('<tariffs><tariff id="1" rate_type="percent">1,5</tariff></tariffs>'), '1,5'];
        yield 'пустая ставка' => [$base('<tariffs><tariff id="1" rate_type="percent"></tariff></tariffs>'), 'тариф'];
        yield 'category_id' => [$base('<tariffs><tariff-category name="x" category_id="x" is_percent="true">1.0</tariff-category></tariffs>'), 'category_id'];
        yield 'категория без id' => [$base('<categories><category><name>Обучение</name></category></categories>'), 'category'];
    }

    #[DataProvider('notCatalogs')]
    public function testNotAMerchantCatalog(string $body): void
    {
        try {
            (new MerchantXmlParser())->parse($body);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString(strlen($body) . ' байт', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notCatalogs(): iterable
    {
        yield 'пустое тело' => [''];
        yield 'пробелы' => ['   '];
        yield 'текст' => ['not xml'];
        yield 'HTML 404' => ['<!DOCTYPE html><html><body>Not Found</body></html>'];
        yield 'JSON' => ['[{"_id":1}]'];
        yield 'другой корень' => ['<?xml version="1.0"?><yml_catalog date="2026-10-07"></yml_catalog>'];
        yield 'обрезанный' => [substr(Fixtures::read('merchants/shops.xml'), 0, 40000)];
        yield 'невалидный UTF-8' => ["<shops><shop><id>7</id><name>\xff\xfe</name><url>https://x.ru/</url></shop></shops>"];
    }

    public function testExternalEntityIsNotExpanded(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE shops [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<shops><shop><id>7</id><name>&xxe;</name><url>https://x.ru/</url></shop></shops>';

        try {
            (new MerchantXmlParser())->parse($xml);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('DOCTYPE', $e->getMessage());
            self::assertStringNotContainsString('root:', $e->getMessage());
        }
    }

    #[DataProvider('dtdAttacks')]
    public function testDtdAttacksAreRejectedWithoutNetwork(string $xml): void
    {
        $started = microtime(true);

        try {
            (new MerchantXmlParser())->parse($xml);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException) {
            self::assertLessThan(1.0, microtime(true) - $started);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dtdAttacks(): iterable
    {
        yield 'внешний DTD по сети' => ['<?xml version="1.0"?><!DOCTYPE shops SYSTEM "http://10.255.255.1/x.dtd"><shops/>'];
        yield 'billion laughs' => ['<?xml version="1.0"?><!DOCTYPE shops [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
            . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">]>'
            . '<shops><shop><id>7</id><name>&d;</name><url>https://x.ru/</url></shop></shops>'];
    }

    public function testLibxmlStateIsRestored(): void
    {
        $parser = new MerchantXmlParser();

        foreach ([false, true] as $initial) {
            $previous = libxml_use_internal_errors($initial);
            try {
                $parser->parse('<shops/>');
                self::assertSame($initial, libxml_use_internal_errors($initial));
                try {
                    $parser->parse('<shops><shop>');
                } catch (UnexpectedResponseException) {
                }
                self::assertSame($initial, libxml_use_internal_errors($initial));
                self::assertSame([], libxml_get_errors());
            } finally {
                libxml_use_internal_errors($previous);
            }
        }
    }

    public function testWindows1251Document(): void
    {
        // синтетика: «Всё» в windows-1251
        $xml = "<?xml version=\"1.0\" encoding=\"windows-1251\"?><shops><shop><id>7</id><name>\xc2\xf1\xb8</name><url>https://x.ru/</url></shop></shops>";

        self::assertSame('Всё', (new MerchantXmlParser())->parse($xml)->get(7)->name());
    }

    private static function fixture(): \Webreboot\GdeSlon\Domain\Catalog\MerchantList
    {
        return (new MerchantXmlParser())->parse(Fixtures::read('merchants/shops.xml'));
    }

    private static function shops(string $shops): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><shops>' . $shops . '</shops>';
    }

    /**
     * @return list<array{string, ?string, RateType, string, list<string>}>
     */
    private static function tariffs(Merchant $merchant): array
    {
        return array_map(
            static fn (Tariff $t): array => [$t->id(), $t->title(), $t->rateType(), $t->rate(), $t->trafficCategories()],
            $merchant->tariffs(),
        );
    }
}
