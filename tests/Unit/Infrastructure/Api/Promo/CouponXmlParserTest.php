<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Promo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCategory;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponKind;
use Webreboot\GdeSlon\Domain\Promo\CouponList;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Promo\CouponXmlParser;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class CouponXmlParserTest extends TestCase
{
    public function testParsesRealShape(): void
    {
        $list = self::parse('coupons.xml');

        self::assertSame([336004, 400636, 403066, 407484, 418483, 441966, 443644], array_map(static fn (Coupon $c): int => $c->id()->value(), $list->all()));
        self::assertSame([], $list->skipped());

        $coupon = $list->find(336004);
        self::assertNotNull($coupon);
        self::assertSame(99157, $coupon->merchantId()->value());
        self::assertSame('elementaree.ru', $coupon->merchantName());
        self::assertSame('Скидка 33% на первый заказ + бесплатная доставка по Москве и МО', $coupon->name());
        self::assertStringStartsWith('Скидка 33% на первый заказ + бесплатная доставка по Москве и МО.', $coupon->description());
        self::assertSame($coupon->description(), $coupon->instruction());
        self::assertSame('PROMO10', $coupon->code());
        self::assertSame(1, $coupon->kind()->id());
        self::assertSame('скидка на заказ', $coupon->kind()->name());
        self::assertSame([[351, 'Питание']], array_map(static fn (CouponCategory $c): array => [$c->id()->value(), $c->name()], $coupon->categories()));
        self::assertSame('2023-04-12T00:00:00+03:00', $coupon->startsAt()->format('c'));
        self::assertSame('2026-12-31T23:59:59+03:00', $coupon->endsAt()->format('c'));
        self::assertSame('http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/336004?erid=2SDnjTEST001', $coupon->affiliateLink());
        self::assertSame('http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/336004?kc=PROMO10&erid=2SDnjTEST001', $coupon->affiliateLinkWithCode(), '&amp; раскрыт');
        self::assertStringStartsWith('Реклама. Рекламодатель ООО "Пример 1" ИНН 7700000001', (string) $coupon->adMarking());
        self::assertStringEndsWith('erid 2SDnjTEST001', (string) $coupon->adMarking());
    }

    public function testCodeVariants(): void
    {
        $list = self::parse('coupons.xml');

        $withoutCode = $list->find(400636);
        self::assertNull($withoutCode?->code());
        self::assertNull($withoutCode?->affiliateLinkWithCode());
        self::assertStringContainsString('erid=2SDnjTEST004', (string) $withoutCode?->affiliateLink());

        self::assertSame('PROMO20', $list->find(403066)?->code(), 'перевод строки в коде из ответа обрезается');
        $cyrillic = $list->find(443644);
        self::assertNotNull($cyrillic);
        self::assertSame('СКИДКА15', $cyrillic->code());
        self::assertStringContainsString('kc=%D0%A1%D0%9A', (string) $cyrillic->affiliateLinkWithCode(), 'ссылка как в ответе');
        self::assertStringContainsString("\n", $list->find(441966)?->description() ?? '', 'перевод строки в описании сохраняется');
    }

    public function testEridAndLinks(): void
    {
        $list = self::parse('coupons.xml');

        $coupon = $list->find(336004) ?? throw new \LogicException();
        self::assertSame('2SDnjTEST001', $coupon->erid());
        self::assertSame($coupon->affiliateLink(), $coupon->link()->url());
        self::assertSame($coupon->affiliateLinkWithCode(), $coupon->linkWithCode()?->url());
        self::assertSame('2SDnjTEST001', $coupon->linkWithCode()?->erid());
        self::assertSame('http://xf.gdeslon.ru/ck/***/336004?erid=2SDnjTEST001&sub_id=site', $coupon->link()->withSubId('site')->masked());
        self::assertStringContainsString('kc=PROMO20%0A&erid=2SDnjTEST002', (string) $list->find(403066)?->linkWithCode()?->url());

        foreach ($list->all() as $each) {
            self::assertNotNull($each->erid(), (string) $each->id());
            self::assertSame($each->link()->erid(), $each->erid(), (string) $each->id());
        }
    }

    public function testLinkWithWhitespaceIsSkippedWithoutToken(): void
    {
        $link = 'http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/500001?kc=A B&amp;erid=2SDnjTEST900';
        $list = self::parseXml(self::document(
            self::coupon(['url' => $link])
            . self::coupon(['id' => '500002', 'code' => 'A', 'url-with-code' => "http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/500002?kc=A&#10;&amp;erid=x"])
            . self::coupon(['id' => '500003', 'url' => " \n http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/500003?erid=2SDnjTEST900 \n"]),
        ));

        self::assertSame([500003], array_map(static fn (Coupon $c): int => $c->id()->value(), $list->all()));
        self::assertSame('http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/500003?erid=2SDnjTEST900', $list->find(500003)?->affiliateLink());
        self::assertCount(2, $list->skipped());
        foreach ($list->skipped() as $i => $reason) {
            self::assertStringContainsString(sprintf('купон 50000%d', $i + 1), $reason);
            self::assertStringContainsString('ссылка', $reason);
            self::assertStringNotContainsString('0a1b2c3d4e', $reason);
        }
    }

    public function testKinds(): void
    {
        $list = self::parse('coupons.xml');

        self::assertSame(14, $list->find(407484)?->kind()->id(), 'SALE');
        self::assertSame(2, $list->find(443644)?->kind()->id(), 'подарок к заказу');
        self::assertCount(16, $list->kinds());
        self::assertContains('Black Friday', array_map(static fn (CouponKind $k): string => $k->name(), $list->kinds()));
    }

    public function testEmptyResponse(): void
    {
        $list = self::parse('coupons-empty.xml');

        self::assertTrue($list->isEmpty());
        self::assertCount(16, $list->kinds());
        self::assertSame([], $list->skipped());
    }

    public function testBrokenRecordsAreSkipped(): void
    {
        $list = self::parse('coupons-broken.xml');

        self::assertSame([500001, 500012], array_map(static fn (Coupon $c): int => $c->id()->value(), $list->all()));
        $skipped = $list->skipped();
        self::assertCount(10, $skipped);
        foreach (['id', 'id', 'merchant-id', 'название', 'start-at', 'начало', 'start-at', 'вид', 'url', 'повтор'] as $i => $reason) {
            self::assertStringContainsString($reason, $skipped[$i], (string) $i);
        }
        foreach ($skipped as $reason) {
            self::assertStringNotContainsString('/ck/', $reason, 'причины без ссылок (в них токен)');
            self::assertStringNotContainsString('0a1b2c3d4e', $reason);
        }

        $orphan = $list->find(500012);
        self::assertNull($orphan?->merchantName(), 'магазина нет в справочнике');
        self::assertSame([], $orphan?->categories(), 'нечисловая категория пропущена');
    }

    public function testMissingCategoryNameInDictionary(): void
    {
        $xml = str_replace('<coupon-categories><coupon-category><id>351</id><name>Питание</name></coupon-category></coupon-categories>', '<coupon-categories></coupon-categories>', Fixtures::read('coupons/coupons-broken.xml'));

        $coupon = (new CouponXmlParser())->parse($xml, new CouponCriteria())->find(500001);

        self::assertNotNull($coupon);
        self::assertSame(351, $coupon->categories()[0]->id()->value());
        self::assertNull($coupon->categories()[0]->name());
    }

    public function testAllBroken(): void
    {
        $xml = (string) preg_replace('~<id>500001</id>|<id>500012</id>~', '<id>bad</id>', Fixtures::read('coupons/coupons-broken.xml'));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('ни одна запись не разобрана');

        (new CouponXmlParser())->parse($xml, new CouponCriteria());
    }

    #[DataProvider('notDocuments')]
    public function testNotDocument(string $xml, string $message): void
    {
        $started = microtime(true);
        try {
            (new CouponXmlParser())->parse($xml, new CouponCriteria());
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notDocuments(): iterable
    {
        yield 'пусто' => ['', 'пустой'];
        yield 'HTML' => ['<html><body>404</body></html>', 'корневой'];
        yield 'обрезан' => [substr(Fixtures::read('coupons/coupons.xml'), 0, 3000), 'XML'];
        yield 'Atom' => ['<feed xmlns="http://www.w3.org/2005/Atom"></feed>', 'корневой'];
        yield 'XXE' => ['<?xml version="1.0"?><!DOCTYPE gdeslon-coupons [<!ENTITY x SYSTEM "file:///etc/passwd">]><gdeslon-coupons><kinds/><coupons/></gdeslon-coupons>', 'DOCTYPE'];
        yield 'ошибка вместо купонов' => [Fixtures::read('coupons/error-401-bad-token.xml'), 'gdeslon-coupons/kinds'];
        yield 'нет справочника видов' => ['<gdeslon-coupons><coupon-categories/><categories/><merchants/><coupons><coupon><id>1</id></coupon></coupons></gdeslon-coupons>', 'gdeslon-coupons/kinds'];
    }

    public function testErrors(): void
    {
        $parser = new CouponXmlParser();

        self::assertSame(['merchant_id' => ['Выберите корректный вариант. 23707 нет среди допустимых значений.']], $parser->errors(Fixtures::read('coupons/error-400-merchant.xml')));
        self::assertSame(['kind' => ['“abc” является неверным значением.']], $parser->errors(Fixtures::read('coupons/error-400-kind.xml')));
        self::assertSame(['detail' => ['Недопустимый токен.']], $parser->errors(Fixtures::read('coupons/error-401-bad-token.xml')));
        foreach (['<html>400</html>', '', '<gdeslon-coupons/>', '<!DOCTYPE x><gdeslon-coupons><a>1</a></gdeslon-coupons>'] as $body) {
            self::assertNull($parser->errors($body), $body);
        }
    }

    private static function parse(string $fixture): CouponList
    {
        return (new CouponXmlParser())->parse(Fixtures::read('coupons/' . $fixture), new CouponCriteria());
    }

    public function testCdataTextsDecoded(): void
    {
        $list = self::parseXml(self::document(
            self::coupon([
                'name' => '<![CDATA[Скидка 10&#37; на &laquo;всё&raquo;]]>',
                'description' => '<![CDATA[Только&nbsp;сегодня&lt;br/&gt;&mdash; до 31.12]]>',
                'instruction' => '<![CDATA[Введите код &quot;в корзине&quot;]]>',
                'tagging_ads' => '<![CDATA[Реклама. Рекламодатель ООО &quot;Пример&quot; ИНН 7700000099]]>',
            ]),
            merchantName: '<![CDATA[Магазин &quot;Слон&quot;]]>',
            categoryName: '<![CDATA[Дом &amp; сад]]>',
        ));

        $coupon = $list->find(500001);
        self::assertNotNull($coupon);
        self::assertSame('Скидка 10% на «всё»', $coupon->name());
        self::assertSame("Только\u{00A0}сегодня<br/>— до 31.12", $coupon->description());
        self::assertSame('Введите код "в корзине"', $coupon->instruction());
        self::assertSame('Реклама. Рекламодатель ООО "Пример" ИНН 7700000099', $coupon->adMarking());
        self::assertSame('Магазин "Слон"', $coupon->merchantName());
        self::assertSame('Дом & сад', $coupon->categories()[0]->name());
    }

    public function testCodeAndLinksNotDecoded(): void
    {
        $link = 'http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/500001?kc=A&amp;erid=2SDnjTEST900';
        $coupon = self::parseXml(self::document(self::coupon([
            'code' => '<![CDATA[A&amp;B]]>',
            'url' => '<![CDATA[' . $link . ']]>',
            'url-with-code' => '<![CDATA[' . $link . ']]>',
        ])))->find(500001);

        self::assertNotNull($coupon);
        self::assertSame('A&amp;B', $coupon->code());
        self::assertSame($link, $coupon->affiliateLink());
        self::assertSame($link, $coupon->affiliateLinkWithCode());
    }

    public function testKindMatchedAfterDecoding(): void
    {
        $kinds = '<kind><id>1</id><name><![CDATA[Скидка &laquo;1+1&raquo;]]></name></kind><kind><id>2</id><name>Подарок &amp; бонус</name></kind>';
        $list = self::parseXml(self::document(
            self::coupon(['kind' => '<![CDATA[Скидка &laquo;1+1&raquo;]]>'])
            . self::coupon(['id' => '500002', 'kind' => 'Скидка «1+1»'])
            . self::coupon(['id' => '500003', 'kind' => '<![CDATA[Подарок &amp; бонус]]>']),
            kinds: $kinds,
        ));

        self::assertSame([], $list->skipped());
        self::assertSame(['Скидка «1+1»', 'Подарок & бонус'], array_map(static fn (CouponKind $k): string => $k->name(), $list->kinds()));
        self::assertSame(1, $list->find(500001)?->kind()->id());
        self::assertSame(1, $list->find(500002)?->kind()->id());
        self::assertSame(2, $list->find(500003)?->kind()->id());
    }

    public function testPlainTextNotDoubleDecoded(): void
    {
        $coupon = self::parseXml(self::document(self::coupon([
            'name' => 'Скидка &amp;quot;X&amp;quot;',
            'description' => '10&amp;#37; &amp; бонус',
        ])))->find(500001);

        self::assertNotNull($coupon);
        self::assertSame('Скидка &quot;X&quot;', $coupon->name());
        self::assertSame('10&#37; & бонус', $coupon->description());
    }

    private static function parseXml(string $xml): CouponList
    {
        return (new CouponXmlParser())->parse($xml, new CouponCriteria());
    }

    private static function document(
        string $coupons,
        string $merchantName = 'elementaree.ru',
        string $categoryName = 'Питание',
        string $kinds = '<kind><id>1</id><name>скидка на заказ</name></kind>',
    ): string {
        return '<?xml version="1.0" encoding="utf-8"?><gdeslon-coupons>'
            . '<coupon-categories><coupon-category><id>351</id><name>' . $categoryName . '</name></coupon-category></coupon-categories>'
            . '<merchants><merchant><url>https://elementaree.ru/</url><name>' . $merchantName . '</name><id>99157</id></merchant></merchants>'
            . '<kinds>' . $kinds . '</kinds><coupons>' . $coupons . '</coupons></gdeslon-coupons>';
    }

    /**
     * @param array<string, string> $fields
     */
    private static function coupon(array $fields = []): string
    {
        $fields += [
            'id' => '500001',
            'merchant-id' => '99157',
            'name' => 'Скидка',
            'kind' => 'скидка на заказ',
            'start-at' => '2026-01-01 00:00:00',
            'finish-at' => '2026-12-31 23:59:59',
            'url' => 'http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/500001?erid=2SDnjTEST900',
            'coupon-categories' => '<coupon-category><id>351</id></coupon-category>',
        ];
        $xml = '<coupon>';
        foreach ($fields as $name => $value) {
            $xml .= sprintf('<%1$s>%2$s</%1$s>', $name, $value);
        }

        return $xml . '</coupon>';
    }

    public function testBrokenDictionaryEntriesDoNotBreakCoupons(): void
    {
        $xml = str_replace(
            ['<name>elementaree.ru</name>', '<name>Питание</name>'],
            ['<name></name>', '<name></name>'],
            Fixtures::read('coupons/coupons-broken.xml'),
        );

        $coupon = (new CouponXmlParser())->parse($xml, new CouponCriteria())->find(500001);

        self::assertNotNull($coupon);
        self::assertNull($coupon->merchantName());
        self::assertSame(351, $coupon->categories()[0]->id()->value());
        self::assertNull($coupon->categories()[0]->name());
    }

    public function testBrokenDictionaryEntriesAreReported(): void
    {
        $xml = str_replace('<name>elementaree.ru</name>', '<name></name>', Fixtures::read('coupons/coupons-broken.xml'));

        self::assertContains('справочник магазинов: битых записей 1 из 1', (new CouponXmlParser())->parse($xml, new CouponCriteria())->skipped());
    }

    public function testMissingAdMarking(): void
    {
        $xml = (string) preg_replace('~<tagging_ads>[^<]*</tagging_ads>~', '<tagging_ads></tagging_ads>', Fixtures::read('coupons/coupons-broken.xml'));

        self::assertNull((new CouponXmlParser())->parse($xml, new CouponCriteria())->find(500001)?->adMarking());
    }

    public function testSkipReasonHasSinglePrefix(): void
    {
        $skipped = self::parse('coupons-broken.xml')->skipped();

        self::assertStringStartsWith('купон 500004: merchant-id', $skipped[2]);
    }

    public function testDictionaryFormatChangeIsReported(): void
    {
        $xml = (string) preg_replace('~<merchant>(.*?)<name>(.*?)</name>~s', '<merchant>$1<title>$2</title>', Fixtures::read('coupons/coupons.xml'));
        $xml = (string) preg_replace('~(<coupon-category><id>\d+</id>)<name>(.*?)</name>~', '$1<title>$2</title>', $xml);

        $list = (new CouponXmlParser())->parse($xml, new CouponCriteria());

        self::assertCount(7, $list, 'купоны остаются');
        self::assertNull($list->find(336004)?->merchantName());
        self::assertSame(['справочник магазинов: битых записей 6 из 6', 'справочник категорий купонов: битых записей 6 из 6'], $list->skipped());
    }
}
