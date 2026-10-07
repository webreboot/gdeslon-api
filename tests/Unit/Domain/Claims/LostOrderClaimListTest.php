<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Claims;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class LostOrderClaimListTest extends TestCase
{
    public function testClaim(): void
    {
        $updated = new \DateTimeImmutable('2026-10-01T00:00:00+03:00');
        $claim = new LostOrderClaim(
            id: 5796,
            orderNumber: ' GS123L ',
            orderDate: new \DateTimeImmutable('2026-09-24T00:00:00+03:00'),
            orderTotal: OrderTotal::of('554.34'),
            merchantId: 2573,
            orderStatus: LostOrderStatus::Waiting,
            claimState: LostOrderClaimState::InWork,
            merchantName: 'Магазин',
            description: '',
            attachmentUrl: ' ',
            orderUpdatedAt: $updated,
        );

        self::assertSame(5796, $claim->id()->value());
        self::assertSame('GS123L', $claim->orderNumber());
        self::assertSame('2026-09-24', $claim->orderDate()->format('Y-m-d'));
        self::assertSame('554.34', $claim->orderTotal()->amount());
        self::assertSame(2573, $claim->merchantId()->value());
        self::assertSame(LostOrderStatus::Waiting, $claim->orderStatus());
        self::assertSame(LostOrderClaimState::InWork, $claim->claimState());
        self::assertSame('Магазин', $claim->merchantName());
        self::assertNull($claim->description());
        self::assertNull($claim->attachmentUrl());
        self::assertSame($updated, $claim->orderUpdatedAt());
        self::assertNull(self::claim(1)->orderUpdatedAt());
    }

    public function testList(): void
    {
        $criteria = new LostOrderCriteria();
        $list = new LostOrderClaimList($criteria, [self::claim(3, 'GS123L'), self::claim(1, 'A-1', 111)], ['заявка #3: битая']);

        self::assertSame($criteria, $list->criteria());
        self::assertCount(2, $list);
        self::assertFalse($list->isEmpty());
        self::assertSame([3, 1], array_map(static fn (LostOrderClaim $c): int => $c->id()->value(), iterator_to_array($list)));
        self::assertSame(1, $list->find(1)?->id()->value());
        self::assertSame(3, $list->find(new LostOrderClaimId(3))?->id()->value());
        self::assertNull($list->find(404));
        self::assertSame(['заявка #3: битая'], $list->skipped());
        self::assertCount(1, $list->filter(static fn (LostOrderClaim $c): bool => $c->merchantId()->value() === 111));
        self::assertTrue((new LostOrderClaimList($criteria, []))->isEmpty());
    }

    public function testFindByOrderNumber(): void
    {
        $list = new LostOrderClaimList(new LostOrderCriteria(), [self::claim(3, 'GS123L', 2573)]);

        self::assertSame(3, $list->findByOrderNumber(new MerchantId(2573), ' gs123l ')?->id()->value());
        self::assertNull($list->findByOrderNumber(new MerchantId(111), 'GS123L'), 'у другого магазина — не дубль');
        self::assertNull($list->findByOrderNumber(new MerchantId(2573), 'GS124L'));
    }

    public function testDuplicateIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('5796');

        new LostOrderClaimList(new LostOrderCriteria(), [self::claim(5796), self::claim(5796)]);
    }

    public function testCriteria(): void
    {
        $empty = new LostOrderCriteria();
        self::assertNull($empty->merchant());
        self::assertNull($empty->from());
        self::assertNull($empty->until());
        self::assertNull($empty->claimState());
        self::assertNull($empty->orderStatus());

        $criteria = new LostOrderCriteria(
            merchant: 2573,
            from: '2026-07-01',
            until: new \DateTimeImmutable('2026-10-07 23:00', new \DateTimeZone('+03:00')),
            claimState: LostOrderClaimState::InWork,
            orderStatus: LostOrderStatus::Waiting,
        );
        self::assertSame(2573, $criteria->merchant()?->value());
        self::assertSame('2026-07-01', $criteria->from());
        self::assertSame('2026-10-07', $criteria->until());

        self::assertTrue($criteria->matches(self::claim(1)));
        self::assertFalse($criteria->matches(self::claim(1, merchant: 111)));
        self::assertFalse($criteria->matches(self::claim(1, status: LostOrderStatus::Confirmed)));
        self::assertFalse($criteria->matches(self::claim(1, state: LostOrderClaimState::Closed)));
        self::assertTrue($empty->matches(self::claim(1, merchant: 111, status: LostOrderStatus::Declined, state: LostOrderClaimState::Closed)));
    }

    public function testInvalidCriteria(): void
    {
        foreach ([
            static fn (): LostOrderCriteria => new LostOrderCriteria(from: '2026-10-08', until: '2026-10-07'),
            static fn (): LostOrderCriteria => new LostOrderCriteria(from: '2026-13-45'),
            static fn (): LostOrderCriteria => new LostOrderCriteria(merchant: 0),
        ] as $create) {
            try {
                $create();
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function claim(
        int $id,
        string $number = 'GS123L',
        int $merchant = 2573,
        LostOrderStatus $status = LostOrderStatus::Waiting,
        LostOrderClaimState $state = LostOrderClaimState::InWork,
    ): LostOrderClaim {
        return new LostOrderClaim($id, $number, new \DateTimeImmutable('2026-09-24'), OrderTotal::of('1'), $merchant, $status, $state);
    }
}
