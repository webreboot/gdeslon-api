<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\AffiliateLink;
use Webreboot\GdeSlon\Domain\Shared\SubId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class AffiliateLinkTest extends TestCase
{
    private const OFFER = 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=107054&goto=https%3A%2F%2Famwine.ru%2Fcatalog%2Fpaket!%2F&erid=2SDnjcZqvUG';
    private const ALIEXPRESS = 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=82012&goto=https%3A%2F%2Fs.click.aliexpress.com%2Fs%2FAbCdEf';
    private const MERCHANT = 'https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=Kra23dyAk&mid=105263';
    private const MERCHANT_WITHOUT_ERID = 'https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=&mid=82012';
    private const TOKEN = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';
    private const COUPON = 'http://xf.gdeslon.ru/ck/' . self::TOKEN . '/336004?erid=2SDnjTEST001';
    private const COUPON_WITH_CODE = 'http://xf.gdeslon.ru/ck/' . self::TOKEN . '/403066?kc=PROMO20%0A&erid=2SDnjTEST002';

    /**
     * @return iterable<string, array{string}>
     */
    public static function realForms(): iterable
    {
        yield 'оффер с сырым ! в goto' => [self::OFFER];
        yield 'оффер AliExpress без erid' => [self::ALIEXPRESS];
        yield 'магазин' => [self::MERCHANT];
        yield 'магазин с пустым erid' => [self::MERCHANT_WITHOUT_ERID];
        yield 'купон' => [self::COUPON];
        yield 'купон с %0A в коде' => [self::COUPON_WITH_CODE];
        yield 'припаркованный домен' => ['http://example.com/cm/0a1b2c3d4e/?mid=1&goto=https%3A%2F%2Fx.ru'];
    }

    #[DataProvider('realForms')]
    public function testRealFormsKeptByteForByte(string $url): void
    {
        $link = AffiliateLink::fromString($url);

        self::assertSame($url, $link->url());
        self::assertSame($url, (string) $link);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLinks(): iterable
    {
        yield 'пусто' => [''];
        yield 'без схемы' => ['af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1'];
        yield 'ftp' => ['ftp://af.gdeslon.ru/cm/0a1b2c3d4e/'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'пробел в goto' => ['https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&goto=https://x.ru/a b'];
        yield 'пробел в начале' => [' https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1'];
        yield 'перевод строки в конце' => ["https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1\n"];
        yield 'табуляция' => ["https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1\t&erid=x"];
        yield 'нулевой байт' => ["https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1\0"];
        yield 'DEL' => ["https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1\x7F"];
    }

    #[DataProvider('invalidLinks')]
    public function testInvalidLinks(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        AffiliateLink::fromString($url);
    }

    public function testInvalidLinkMessageHasNoUrl(): void
    {
        try {
            AffiliateLink::fromString('http://xf.gdeslon.ru/ck/' . self::TOKEN . '/1?kc=A B');
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('xf.gdeslon.ru', $e->getMessage());
            self::assertStringContainsString('http(s)://', $e->getMessage());
        }
    }

    public function testErid(): void
    {
        self::assertSame('2SDnjcZqvUG', AffiliateLink::fromString(self::OFFER)->erid());
        self::assertSame('Kra23dyAk', AffiliateLink::fromString(self::MERCHANT)->erid());
        self::assertSame('2SDnjTEST002', AffiliateLink::fromString(self::COUPON_WITH_CODE)->erid());
        self::assertNull(AffiliateLink::fromString(self::ALIEXPRESS)->erid());
        self::assertNull(AffiliateLink::fromString(self::MERCHANT_WITHOUT_ERID)->erid());
        self::assertNull(AffiliateLink::fromString('https://x.ru/?erid=a%20b')->erid());
        self::assertNull(AffiliateLink::fromString('https://x.ru/?goto=https%3A%2F%2Fy.ru%2F%3Ferid%3D2SDnjTEST009')->erid());
        self::assertNull(AffiliateLink::fromString('https://x.ru/p#erid=2SDnjTEST009')->erid());
        self::assertSame('2SDnjTEST010', AffiliateLink::fromString('https://x.ru/?erid=2SDnjTEST010&erid=2SDnjTEST011')->erid());
    }

    public function testMerchantId(): void
    {
        self::assertSame(107054, AffiliateLink::fromString(self::OFFER)->merchantId()?->value());
        self::assertSame(105263, AffiliateLink::fromString(self::MERCHANT)->merchantId()?->value());
        self::assertNull(AffiliateLink::fromString(self::COUPON)->merchantId());
        self::assertNull(AffiliateLink::fromString('https://x.ru/?mid=abc')->merchantId());
        self::assertNull(AffiliateLink::fromString('https://x.ru/?mid=0')->merchantId());
        self::assertNull(AffiliateLink::fromString('https://x.ru/?mid=')->merchantId());
    }

    public function testSubIdReadsExisting(): void
    {
        $link = AffiliateLink::fromString('http://xf.gdeslon.ru/ck/' . self::TOKEN . '/1?sub_id=None&kc=X&sub_id3=c');

        self::assertSame('None', $link->subId());
        self::assertNull($link->subId(2));
        self::assertSame('c', $link->subId(3));
        self::assertNull(AffiliateLink::fromString(self::OFFER)->subId());
        self::assertNull(AffiliateLink::fromString('https://f.gdeslon.ru/cf/0a1b2c3d4e')->subId());
        self::assertNull(AffiliateLink::fromString('https://x.ru/?sub_id=')->subId());
        self::assertNull(AffiliateLink::fromString('https://x.ru/?sub_id&b=2')->subId());
    }

    public function testWithSubIdAppendsToEndKeepingBytes(): void
    {
        self::assertSame(self::OFFER . '&sub_id=tg_channel-42', AffiliateLink::fromString(self::OFFER)->withSubId('tg_channel-42')->url());
        self::assertSame(self::MERCHANT_WITHOUT_ERID . '&sub_id=site', AffiliateLink::fromString(self::MERCHANT_WITHOUT_ERID)->withSubId('site')->url());
        self::assertSame(self::COUPON_WITH_CODE . '&sub_id=site', AffiliateLink::fromString(self::COUPON_WITH_CODE)->withSubId('site')->url());
    }

    public function testWithSubIdWithoutQuery(): void
    {
        self::assertSame('https://f.gdeslon.ru/cf/0a1b2c3d4e?sub_id=a', AffiliateLink::fromString('https://f.gdeslon.ru/cf/0a1b2c3d4e')->withSubId('a')->url());
        self::assertSame('https://x.ru/p?sub_id=a', AffiliateLink::fromString('https://x.ru/p?')->withSubId('a')->url());
        self::assertSame('https://x.ru/p?mid=1&sub_id=a', AffiliateLink::fromString('https://x.ru/p?mid=1&')->withSubId('a')->url());
    }

    public function testWithSubIdReplacesInPlace(): void
    {
        $link = AffiliateLink::fromString('http://xf.gdeslon.ru/ck/' . self::TOKEN . '/1?sub_id=None&kc=X');

        self::assertSame('http://xf.gdeslon.ru/ck/' . self::TOKEN . '/1?sub_id=site&kc=X', $link->withSubId('site')->url());
        self::assertSame('https://x.ru/?a=1&sub_id=site&b=2', AffiliateLink::fromString('https://x.ru/?a=1&sub_id&b=2')->withSubId('site')->url());
    }

    public function testWithSubIdCollapsesDuplicates(): void
    {
        $link = AffiliateLink::fromString('https://x.ru/?sub_id=a&mid=1&sub_id=b&erid=2SDnjTEST001&sub_id=');

        self::assertSame('https://x.ru/?sub_id=site&mid=1&erid=2SDnjTEST001', $link->withSubId('site')->url());
    }

    public function testPositionsUseOwnKeys(): void
    {
        $link = AffiliateLink::fromString('https://x.ru/?sub_id=a&SUB_ID2=x');

        self::assertSame('https://x.ru/?sub_id=a&SUB_ID2=x&sub_id2=b', $link->withSubId('b', 2)->url());
        self::assertSame('https://x.ru/?sub_id=a&SUB_ID2=x&sub_id5=e', $link->withSubId('e', 5)->url());
        self::assertSame('https://x.ru/?sub_id2=b&sub_id=a', AffiliateLink::fromString('https://x.ru/?sub_id2=b')->withSubId('a')->url());
        self::assertSame('https://x.ru/?sub_id2=c&sub_id=a', AffiliateLink::fromString('https://x.ru/?sub_id2=b&sub_id=a')->withSubId('c', 2)->url());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPositions(): iterable
    {
        yield 'ноль' => [0];
        yield 'шесть' => [6];
        yield 'отрицательная' => [-1];
    }

    #[DataProvider('invalidPositions')]
    public function testInvalidPosition(int $position): void
    {
        $link = AffiliateLink::fromString(self::OFFER);

        try {
            $link->withSubId('site', $position);
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('1 до 5', $e->getMessage());
            self::assertStringNotContainsString('0a1b2c3d4e', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $link->subId($position);
    }

    public function testInvalidSubIdLeavesLinkUnchanged(): void
    {
        $link = AffiliateLink::fromString(self::OFFER);

        try {
            $link->withSubId('bad value&x=1');
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('bad value', $e->getMessage());
            self::assertStringNotContainsString('0a1b2c3d4e', $e->getMessage());
        }

        self::assertSame(self::OFFER, $link->url());
    }

    public function testFragmentAndPortKept(): void
    {
        self::assertSame('https://x.ru:8443/p?a=1&sub_id=s#frag', AffiliateLink::fromString('https://x.ru:8443/p?a=1#frag')->withSubId('s')->url());
        self::assertSame('https://x.ru/p?sub_id=s#frag?b=2&sub_id=x', AffiliateLink::fromString('https://x.ru/p#frag?b=2&sub_id=x')->withSubId('s')->url());
        self::assertSame('https://x.ru/p?sub_id=s#', AffiliateLink::fromString('https://x.ru/p?sub_id=old#')->withSubId('s')->url());
    }

    public function testEncodedSubIdInsideGotoUntouched(): void
    {
        $url = 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&goto=https%3A%2F%2Fshop.ru%2F%3Fsub_id%3D7%26a%3D1&sub%5Fid=8';

        self::assertSame($url . '&sub_id=site', AffiliateLink::fromString($url)->withSubId('site')->url());
        self::assertNull(AffiliateLink::fromString($url)->subId());
    }

    public function testAcceptsSubIdObject(): void
    {
        self::assertSame(self::OFFER . '&sub_id3=vk', AffiliateLink::fromString(self::OFFER)->withSubId(new SubId('vk'), 3)->url());
    }

    public function testImmutable(): void
    {
        $link = AffiliateLink::fromString(self::OFFER);
        $tagged = $link->withSubId('site');

        self::assertNotSame($link, $tagged);
        self::assertSame(self::OFFER, $link->url());
        self::assertNull($link->subId());
        self::assertSame('site', $tagged->subId());
    }

    public function testChainedSubIds(): void
    {
        $link = AffiliateLink::fromString('https://f.gdeslon.ru/cf/0a1b2c3d4e?mid=1')
            ->withSubId('a')
            ->withSubId('b', 2)
            ->withSubId('c');

        self::assertSame('https://f.gdeslon.ru/cf/0a1b2c3d4e?mid=1&sub_id=c&sub_id2=b', $link->url());
        self::assertSame('c', $link->subId());
        self::assertSame('b', $link->subId(2));
        self::assertSame(1, $link->merchantId()?->value());
    }

    public function testMaskedHidesCouponToken(): void
    {
        self::assertSame('http://xf.gdeslon.ru/ck/***/336004?erid=2SDnjTEST001', AffiliateLink::fromString(self::COUPON)->masked());
        self::assertSame(
            'http://xf.gdeslon.ru/ck/***/336004?erid=2SDnjTEST001&sub_id=site',
            AffiliateLink::fromString(self::COUPON)->withSubId('site')->masked(),
        );
        self::assertSame('http://xf.gdeslon.ru/ck/***?erid=2SDnjTEST001', AffiliateLink::fromString('http://xf.gdeslon.ru/ck/' . self::TOKEN . '?erid=2SDnjTEST001')->masked());
        self::assertSame('http://xf.gdeslon.ru/ck/***#x', AffiliateLink::fromString('http://xf.gdeslon.ru/ck/' . self::TOKEN . '#x')->masked());
        self::assertSame('http://xf.gdeslon.ru/ck/***', AffiliateLink::fromString('http://xf.gdeslon.ru/ck/' . self::TOKEN)->masked());
        self::assertSame(self::OFFER, AffiliateLink::fromString(self::OFFER)->masked());
        self::assertSame(self::MERCHANT, AffiliateLink::fromString(self::MERCHANT)->masked());
    }

    public function testDumpsHideCouponToken(): void
    {
        $link = AffiliateLink::fromString(self::COUPON_WITH_CODE)->withSubId('site');

        ob_start();
        var_dump($link);
        $dump = (string) ob_get_clean() . print_r($link, true);

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringContainsString('/ck/***/403066?kc=PROMO20%0A&erid=2SDnjTEST002&sub_id=site', $dump);
    }
}
