<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Promo;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCategory;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponId;
use Webreboot\GdeSlon\Domain\Promo\CouponKind;
use Webreboot\GdeSlon\Domain\Promo\CouponList;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class CouponTest extends TestCase
{
    private const LINK = 'http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/336004?erid=2SDnjTEST001';
    private const LINK_WITH_CODE = 'http://xf.gdeslon.ru/ck/0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e/336004?kc=PROMO10&erid=2SDnjTEST001';

    public function testValues(): void
    {
        $id = new CouponId(461847);
        self::assertSame(461847, $id->value());
        self::assertSame('461847', (string) $id);
        self::assertTrue($id->equals(new CouponId(461847)));

        $kind = new CouponKind(1, ' скидка на заказ ');
        self::assertSame(1, $kind->id());
        self::assertSame('скидка на заказ', $kind->name());
        self::assertTrue($kind->equals(new CouponKind(1, 'другое имя')));
        self::assertTrue((new CouponKind(null, 'SALE'))->equals(new CouponKind(null, 'SALE')));
        self::assertFalse((new CouponKind(null, 'SALE'))->equals(new CouponKind(14, 'SALE')));

        $category = new CouponCategory(new CategoryId(351), 'Питание');
        self::assertSame(351, $category->id()->value());
        self::assertSame('Питание', $category->name());
        self::assertNull((new CouponCategory(351, null))->name());

        foreach ([
            static fn () => new CouponId(0),
            static fn () => new CouponId(-1),
            static fn () => new CouponKind(1, '  '),
            static fn () => new CouponKind(0, 'x'),
        ] as $create) {
            try {
                $create();
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCoupon(): void
    {
        $coupon = self::coupon(code: " PROMO10\n");

        self::assertSame(336004, $coupon->id()->value());
        self::assertSame(99157, $coupon->merchantId()->value());
        self::assertSame('elementaree.ru', $coupon->merchantName());
        self::assertSame('Скидка 33% на первый заказ', $coupon->name());
        self::assertSame("Первая строка\nвторая", $coupon->description());
        self::assertSame('Ввести код при оформлении', $coupon->instruction());
        self::assertSame('PROMO10', $coupon->code());
        self::assertTrue($coupon->hasCode());
        self::assertSame(1, $coupon->kind()->id());
        self::assertSame([351], array_map(static fn (CouponCategory $c): int => $c->id()->value(), $coupon->categories()));
        self::assertSame(self::LINK, $coupon->affiliateLink());
        self::assertSame(self::LINK_WITH_CODE, $coupon->affiliateLinkWithCode());
        self::assertSame('Реклама. Рекламодатель ООО "Пример 1" ИНН 7700000001. erid 2SDnjTEST001', $coupon->adMarking());
        self::assertSame('СКИДКА15', self::coupon(code: 'СКИДКА15')->code());
    }

    public function testCouponWithoutCode(): void
    {
        foreach (['', '  ', null] as $code) {
            $coupon = self::coupon(code: $code);
            self::assertNull($coupon->code());
            self::assertFalse($coupon->hasCode());
            self::assertNull($coupon->affiliateLinkWithCode(), 'без кода ссылки с кодом нет');
        }
    }

    public function testInvariants(): void
    {
        foreach ([
            static fn () => self::coupon(starts: '2026-12-31 23:59:59', ends: '2026-01-01 00:00:00'),
            static fn () => self::coupon(name: " \t"),
            static fn () => self::coupon(link: ''),
            static fn () => self::coupon(link: 'xf.gdeslon.ru/ck/x/1'),
        ] as $create) {
            try {
                $create();
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $instant = self::coupon(starts: '2026-01-01 00:00:00', ends: '2026-01-01 00:00:00');
        self::assertEquals($instant->startsAt(), $instant->endsAt(), 'начало = конец допустимо');
    }

    public function testActivityBoundaries(): void
    {
        $coupon = self::coupon(starts: '2026-01-01 00:00:00', ends: '2026-12-31 23:59:59');
        $moscow = new \DateTimeZone('Europe/Moscow');

        $before = new \DateTimeImmutable('2025-12-31 23:59:59', $moscow);
        $start = new \DateTimeImmutable('2026-01-01 00:00:00', $moscow);
        $end = new \DateTimeImmutable('2026-12-31 23:59:59', $moscow);
        $after = new \DateTimeImmutable('2027-01-01 00:00:00', $moscow);
        $endInUtc = new \DateTimeImmutable('2026-12-31 20:59:59Z');

        self::assertFalse($coupon->isActiveAt($before));
        self::assertTrue($coupon->isActiveAt($start));
        self::assertTrue($coupon->isActiveAt($end));
        self::assertTrue($coupon->isActiveAt($endInUtc));
        self::assertFalse($coupon->isActiveAt($after));

        self::assertFalse($coupon->hasStartedAt($before));
        self::assertTrue($coupon->hasStartedAt($start));
        self::assertFalse($coupon->hasEndedAt($end));
        self::assertTrue($coupon->hasEndedAt($after));
    }

    public function testTokenInLinksIsHiddenFromDumps(): void
    {
        $coupon = self::coupon();

        ob_start();
        var_dump($coupon);
        $dump = (string) ob_get_clean() . print_r($coupon, true);

        self::assertStringNotContainsString('0a1b2c3d4e0a1b2c3d4e', $dump);
        self::assertStringContainsString('/ck/***/336004', $dump);
    }

    public function testCriteria(): void
    {
        $empty = new CouponCriteria();
        self::assertSame([], $empty->merchants());
        self::assertSame([], $empty->kinds());

        $criteria = new CouponCriteria(merchants: [99157, new MerchantId(118031), 99157], kinds: [14, 1, 14]);
        self::assertSame([99157, 118031], array_map(static fn (MerchantId $m): int => $m->value(), $criteria->merchants()));
        self::assertSame([14, 1], $criteria->kinds());
        self::assertSame([99157], array_map(static fn (MerchantId $m): int => $m->value(), CouponCriteria::forMerchant(99157)->merchants()));

        foreach ([static fn () => new CouponCriteria(kinds: [0]), static fn () => new CouponCriteria(merchants: [-1])] as $create) {
            try {
                $create();
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testList(): void
    {
        $criteria = new CouponCriteria();
        $kinds = [new CouponKind(1, 'скидка на заказ'), new CouponKind(14, 'SALE')];
        $active = self::coupon(id: 1, starts: '2026-01-01 00:00:00', ends: '2026-12-31 23:59:59');
        $future = self::coupon(id: 2, starts: '2027-01-01 00:00:00', ends: '2027-12-31 23:59:59');
        $list = new CouponList($criteria, [$active, $future], $kinds, ['купон 3: битый']);

        self::assertSame($criteria, $list->criteria());
        self::assertCount(2, $list);
        self::assertFalse($list->isEmpty());
        self::assertSame([1, 2], array_map(static fn (Coupon $c): int => $c->id()->value(), iterator_to_array($list)));
        self::assertSame($future, $list->find(2));
        self::assertSame($active, $list->find(new CouponId(1)));
        self::assertNull($list->find(404));
        self::assertSame([$active], $list->activeAt(new \DateTimeImmutable('2026-10-07T10:00:00Z')));
        self::assertSame([$future], $list->filter(static fn (Coupon $c): bool => $c->id()->value() === 2));
        self::assertSame($kinds, $list->kinds());
        self::assertSame(['купон 3: битый'], $list->skipped());
        self::assertTrue((new CouponList($criteria, []))->isEmpty());

        $this->expectException(InvalidArgumentException::class);
        new CouponList($criteria, [$active, $active]);
    }

    private static function coupon(
        int $id = 336004,
        ?string $code = 'PROMO10',
        string $name = "Скидка 33% на первый заказ\t",
        string $link = self::LINK,
        string $starts = '2023-04-12 00:00:00',
        string $ends = '2026-12-31 23:59:59',
    ): Coupon {
        $moscow = new \DateTimeZone('Europe/Moscow');

        return new Coupon(
            id: $id,
            merchantId: 99157,
            name: $name,
            kind: new CouponKind(1, 'скидка на заказ'),
            startsAt: new \DateTimeImmutable($starts, $moscow),
            endsAt: new \DateTimeImmutable($ends, $moscow),
            affiliateLink: str_replace('336004', (string) $id, $link),
            merchantName: 'elementaree.ru',
            description: "Первая строка\r\nвторая ",
            instruction: 'Ввести код при оформлении',
            code: $code,
            categories: [new CouponCategory(351, 'Питание')],
            affiliateLinkWithCode: str_replace('336004', (string) $id, self::LINK_WITH_CODE),
            adMarking: 'Реклама. Рекламодатель ООО "Пример 1" ИНН 7700000001. erid 2SDnjTEST001',
        );
    }
}
