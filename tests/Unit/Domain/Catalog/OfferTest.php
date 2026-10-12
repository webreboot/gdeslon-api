<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\AffiliateLink;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\Picture;
use Webreboot\GdeSlon\Domain\Catalog\OfferKey;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OfferTest extends TestCase
{
    private const LINK = 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=107054&goto=https%3A%2F%2Fx.ru&erid=abc';

    public function testMinimalOfferDefaults(): void
    {
        $offer = new Offer('14367733380732236000', new MerchantId(107054), 'Пакет подарочный', new Money('100', 'RUR'), self::LINK);

        self::assertSame('14367733380732236000', $offer->id());
        self::assertSame(107054, $offer->merchantId()->value());
        self::assertSame('Пакет подарочный', $offer->name());
        self::assertSame('100 RUR', (string) $offer->price());
        self::assertSame(self::LINK, $offer->affiliateLink());
        self::assertNull($offer->oldPrice());
        self::assertNull($offer->charge());
        self::assertNull($offer->article());
        self::assertNull($offer->categoryId());
        self::assertTrue($offer->isAvailable());
        self::assertNull($offer->picture());
        self::assertNull($offer->thumbnail());
        self::assertNull($offer->originalPicture());
        self::assertNull($offer->description());
        self::assertNull($offer->vendor());
        self::assertNull($offer->model());
        self::assertNull($offer->productUrl());
        self::assertNull($offer->adMarking());
    }

    #[DataProvider('invalidOffers')]
    public function testInvariants(string $id, string $name, string $link): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Offer($id, new MerchantId(1), $name, new Money('1', 'RUR'), $link);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidOffers(): iterable
    {
        yield 'пустое имя' => ['1', '  ', self::LINK];
        yield 'пустой id' => ['', 'x', self::LINK];
        yield 'id с буквами' => ['abc', 'x', self::LINK];
        yield 'отрицательный id' => ['-1', 'x', self::LINK];
        yield 'id с переводом строки' => ["1\n", 'x', self::LINK];
        yield 'имя из неразрывных пробелов' => ['1', "\u{00A0} \u{00A0}", self::LINK];
        yield 'пустая ссылка' => ['1', 'x', ''];
        yield 'ссылка без схемы' => ['1', 'x', 'af.gdeslon.ru/cm/x'];
        yield 'ссылка ftp' => ['1', 'x', 'ftp://x'];
        yield 'ссылка с пробелом' => ['1', 'x', 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&goto=https://x.ru/a b'];
        yield 'ссылка с переводом строки' => ['1', 'x', self::LINK . "\n"];
    }

    public function testInvalidLinkMessageHasOfferIdButNoLink(): void
    {
        try {
            new Offer('42', new MerchantId(1), 'x', new Money('1', 'RUB'), 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?goto=a b');
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('оффера 42', $e->getMessage());
            self::assertStringNotContainsString('0a1b2c3d4e', $e->getMessage());
        }
    }

    public function testLinkMatchesAffiliateLink(): void
    {
        $offer = new Offer('1', new MerchantId(107054), 'x', new Money('1', 'RUB'), self::LINK);

        self::assertSame(self::LINK, $offer->link()->url());
        self::assertSame($offer->affiliateLink(), $offer->link()->url());
        self::assertSame(self::LINK . '&sub_id=site', $offer->link()->withSubId('site')->url());
        self::assertSame(self::LINK, $offer->affiliateLink());
    }

    public function testAcceptsAffiliateLinkInstance(): void
    {
        $link = AffiliateLink::fromString(self::LINK);

        $offer = new Offer('1', new MerchantId(107054), 'x', new Money('1', 'RUB'), $link);

        self::assertSame($link, $offer->link());
        self::assertSame(self::LINK, $offer->affiliateLink());
    }

    public function testEridFromMarkingFirst(): void
    {
        $offer = self::offerWith('https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&erid=2SDnjLINK01', 'Реклама. ООО «Слон», https://x.ru/ erid 2SDnjMARK01');

        self::assertSame('2SDnjMARK01', $offer->erid());
    }

    public function testEridFallsBackToLink(): void
    {
        $link = 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&erid=2SDnjLINK01';

        self::assertSame('2SDnjLINK01', self::offerWith($link, 'Реклама. Рекламодатель ООО «Слон» ИНН 7700000000 , https://x.ru')->erid());
        self::assertSame('2SDnjLINK01', self::offerWith($link, null)->erid());
        self::assertSame('2SDnjLINK01', self::offerWith($link, 'Реклама. Рекламодатель ООО Eridanus ИНН 7700000000 , https://eridanus.ru')->erid());
    }

    public function testTruncatedMarkingEridFallsBackToLink(): void
    {
        $marking = 'Реклама. Рекламодатель ООО «Слон» ERID: 2SDn-TEST';

        self::assertSame('2SDnjLINK01', self::offerWith('https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&erid=2SDnjLINK01', $marking)->erid());
        self::assertNull(self::offerWith('https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1', $marking)->erid());

        foreach (['Реклама. ООО «Слон» erid 2SDnjcZ...', 'Реклама. ООО «Слон», erid: undefined'] as $broken) {
            self::assertSame('2SDnjLINK01', self::offerWith('https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&erid=2SDnjLINK01', $broken)->erid(), $broken);
        }
    }

    public function testEridNullWhenNowhere(): void
    {
        $link = 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=82012&goto=https%3A%2F%2Fs.click.aliexpress.com%2Fs%2FAbC';

        self::assertNull(self::offerWith($link, 'Реклама. Рекламодатель http://aliexpress.com/.')->erid());
        self::assertNull(self::offerWith($link, null)->erid());
        self::assertNull(self::offerWith(self::LINK, null)->erid(), 'erid=abc — не erid');
    }

    public function testKey(): void
    {
        $offer = new Offer('1', new MerchantId(107054), 'x', new Money('1', 'RUB'), self::LINK, article: '578237');

        self::assertSame('107054:578237', (string) $offer->key());
        self::assertTrue(OfferKey::fromString('107054:578237')->equals($offer->key() ?? throw new \LogicException()));
        self::assertNull((new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUB'), self::LINK))->key());
        self::assertNull((new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUB'), self::LINK, article: '  '))->key());
        $other = new Offer('2', new MerchantId(113068), 'y', new Money('1', 'RUB'), self::LINK, article: '578237');
        self::assertFalse($other->key()?->equals($offer->key()));
    }

    public function testParkedDomainLinkIsAccepted(): void
    {
        $offer = new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUR'), 'http://example.com/cm/0a1b2c3d4e/?mid=1');

        self::assertSame('http://example.com/cm/0a1b2c3d4e/?mid=1', $offer->affiliateLink());
    }

    public function testPricesShareCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUR'), self::LINK, oldPrice: new Money('2', 'USD'));
    }

    public function testChargeSharesCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUR'), self::LINK, charge: new Money('0.5', 'USD'));
    }

    public function testTextsAreNormalized(): void
    {
        $offer = new Offer(
            '1',
            new MerchantId(1),
            '  Платье «Анабель» ',
            new Money('1', 'RUR'),
            self::LINK,
            description: "  **Описание**\r\n- пункт  ",
            vendor: ' ',
            model: '',
            adMarking: '   ',
        );

        self::assertSame('Платье «Анабель»', $offer->name());
        self::assertSame("**Описание**\n- пункт", $offer->description());
        self::assertNull($offer->vendor());
        self::assertNull($offer->model());
        self::assertNull($offer->adMarking());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zeroCharges(): iterable
    {
        yield 'ноль' => ['0'];
        yield 'ноль с копейками' => ['0.00'];
    }

    #[DataProvider('zeroCharges')]
    public function testZeroChargeIsRejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('вознаграждение');

        new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUB'), self::LINK, charge: new Money($amount, 'RUB'));
    }

    public function testNonZeroChargeIsAccepted(): void
    {
        $offer = new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUB'), self::LINK, charge: new Money('0.01', 'RUB'));

        self::assertSame('0.01 RUB', (string) $offer->charge());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidOptionalUrls(): iterable
    {
        foreach (['picture', 'thumbnail', 'originalPicture', 'productUrl'] as $field) {
            yield $field . ': javascript' => [$field, 'javascript:alert(1)'];
            yield $field . ': относительный' => [$field, '/img/a.jpg'];
            yield $field . ': пусто' => [$field, ''];
        }
    }

    #[DataProvider('invalidOptionalUrls')]
    public function testOptionalUrlsMustBeHttp(string $field, string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('http(s)://');

        $urls = array_replace(['picture' => null, 'thumbnail' => null, 'originalPicture' => null, 'productUrl' => null], [$field => $url]);

        new Offer(
            '1',
            new MerchantId(1),
            'x',
            new Money('1', 'RUB'),
            self::LINK,
            picture: $urls['picture'],
            thumbnail: $urls['thumbnail'],
            originalPicture: $urls['originalPicture'],
            productUrl: $urls['productUrl'],
        );
    }

    public function testHttpOptionalUrlsAreKept(): void
    {
        $offer = new Offer(
            '1',
            new MerchantId(1),
            'x',
            new Money('1', 'RUB'),
            self::LINK,
            picture: 'https://imgng.gdeslon.ru/p/big.jpg',
            thumbnail: 'https://imgng.gdeslon.ru/p/small.jpg',
            originalPicture: 'http://cdn.example.ru/a.png',
            productUrl: 'https://example.ru/item/1',
        );

        self::assertSame('https://imgng.gdeslon.ru/p/big.jpg', $offer->picture());
        self::assertSame('https://imgng.gdeslon.ru/p/small.jpg', $offer->thumbnail());
        self::assertSame('http://cdn.example.ru/a.png', $offer->originalPicture());
        self::assertSame('https://example.ru/item/1', $offer->productUrl());
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, list<string>}>
     */
    public static function pictureSets(): iterable
    {
        yield 'все три' => ['https://p/big.jpg', 'https://p/small.jpg', 'https://o/a.png', ['https://o/a.png', 'https://p/big.jpg', 'https://p/small.jpg']];
        yield 'без оригинала' => ['https://p/big.jpg', 'https://p/small.jpg', null, ['https://p/big.jpg', 'https://p/small.jpg']];
        yield 'только миниатюра' => [null, 'https://p/small.jpg', null, ['https://p/small.jpg']];
        yield 'только оригинал' => [null, null, 'http://o/a.png', ['http://o/a.png']];
        yield 'без картинок' => [null, null, null, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('pictureSets')]
    public function testPictureCandidatesOrderAndFallbacks(?string $picture, ?string $thumbnail, ?string $original, array $expected): void
    {
        $offer = new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUB'), self::LINK, picture: $picture, thumbnail: $thumbnail, originalPicture: $original);

        self::assertSame($expected, $offer->pictureCandidates());
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, list<array{string, string}>}>
     */
    public static function pictureSetsWithSources(): iterable
    {
        yield 'все три' => ['https://p/big.jpg', 'https://p/small.jpg', 'https://o/a.png', [['https://o/a.png', 'original_picture'], ['https://p/big.jpg', 'picture'], ['https://p/small.jpg', 'thumbnail']]];
        yield 'без оригинала' => ['https://p/big.jpg', 'https://p/small.jpg', null, [['https://p/big.jpg', 'picture'], ['https://p/small.jpg', 'thumbnail']]];
        yield 'только миниатюра' => [null, 'https://p/small.jpg', null, [['https://p/small.jpg', 'thumbnail']]];
        yield 'только оригинал' => [null, null, 'http://o/a.png', [['http://o/a.png', 'original_picture']]];
        yield 'без картинок' => [null, null, null, []];
        yield 'без средней' => [null, 'https://p/small.jpg', 'https://o/a.png', [['https://o/a.png', 'original_picture'], ['https://p/small.jpg', 'thumbnail']]];
        yield 'picture = thumbnail' => ['https://p/a.jpg', 'https://p/a.jpg', null, [['https://p/a.jpg', 'picture']]];
        yield 'original = picture' => ['https://p/a.jpg', 'https://p/small.jpg', 'https://p/a.jpg', [['https://p/a.jpg', 'original_picture'], ['https://p/small.jpg', 'thumbnail']]];
        yield 'original = thumbnail' => ['https://p/big.jpg', 'https://p/a.jpg', 'https://p/a.jpg', [['https://p/a.jpg', 'original_picture'], ['https://p/big.jpg', 'picture']]];
        yield 'все три равны' => ['https://p/a.jpg', 'https://p/a.jpg', 'https://p/a.jpg', [['https://p/a.jpg', 'original_picture']]];
        yield 'схема различается' => ['https://x/a.jpg', null, 'http://x/a.jpg', [['http://x/a.jpg', 'original_picture'], ['https://x/a.jpg', 'picture']]];
        yield 'регистр хоста различается' => ['https://x/a.jpg', null, 'https://X/a.jpg', [['https://X/a.jpg', 'original_picture'], ['https://x/a.jpg', 'picture']]];
    }

    /**
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('pictureSetsWithSources')]
    public function testPicturesOrderSourcesAndDuplicates(?string $picture, ?string $thumbnail, ?string $original, array $expected): void
    {
        $offer = new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUB'), self::LINK, picture: $picture, thumbnail: $thumbnail, originalPicture: $original);

        $pictures = $offer->pictures();

        self::assertSame($expected, array_map(static fn (Picture $p): array => [$p->url(), $p->source()->value], $pictures));
        self::assertSame(array_map(static fn (Picture $p): string => $p->url(), $pictures), $offer->pictureCandidates());
        self::assertEquals($pictures, $offer->pictures());
    }

    public function testPictureCandidatesAreUnique(): void
    {
        $offer = new Offer(
            '1',
            new MerchantId(1),
            'x',
            new Money('1', 'RUB'),
            self::LINK,
            picture: 'https://p/big.jpg',
            thumbnail: 'https://p/big.jpg',
            originalPicture: 'https://p/big.jpg',
        );

        self::assertSame(['https://p/big.jpg'], $offer->pictureCandidates());
    }

    private static function offerWith(string $link, ?string $adMarking): Offer
    {
        return new Offer('1', new MerchantId(1), 'x', new Money('1', 'RUB'), $link, adMarking: $adMarking);
    }
}
