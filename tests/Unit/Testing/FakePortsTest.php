<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponKind;
use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Testing\FakeCategoryRepository;
use Webreboot\GdeSlon\Testing\FakeCouponFeed;
use Webreboot\GdeSlon\Testing\FakeLostOrderClaims;
use Webreboot\GdeSlon\Testing\FakeMerchantRepository;
use Webreboot\GdeSlon\Testing\FakeOrderRepository;
use Webreboot\GdeSlon\Testing\FakeProductCatalog;

final class FakePortsTest extends TestCase
{
    public function testProductCatalogQueuesPagesAndRecordsCriteria(): void
    {
        $catalog = new FakeProductCatalog([
            static fn (SearchCriteria $c): SearchResult => new SearchResult($c, [], 3),
            static fn (SearchCriteria $c): SearchResult => new SearchResult($c, [], 0),
        ]);
        $first = new SearchCriteria(query: 'платье');
        $second = new SearchCriteria(query: 'тренч');

        self::assertSame($first, $catalog->search($first)->criteria());
        self::assertSame($second, $catalog->search($second)->criteria());
        self::assertSame([$first, $second], $catalog->criteria());

        $this->expectException(\LogicException::class);
        $catalog->search($first);
    }

    public function testCategoryRepositoryCountsCallsAndRefresh(): void
    {
        $tree = new CategoryTree([]);
        $repository = new FakeCategoryRepository($tree);

        self::assertSame(0, $repository->calls());
        self::assertNull($repository->lastRefresh());
        self::assertSame($tree, $repository->all());
        self::assertFalse($repository->lastRefresh());
        self::assertSame($tree, $repository->all(refresh: true));
        self::assertTrue($repository->lastRefresh());
        self::assertSame(2, $repository->calls());
    }

    public function testMerchantRepositoryRecordsPublicAndRefresh(): void
    {
        $list = new MerchantList([]);
        $repository = new FakeMerchantRepository($list);

        self::assertNull($repository->lastPublic());
        self::assertNull($repository->lastRefresh());
        self::assertSame($list, $repository->all(public: true));
        self::assertTrue($repository->lastPublic());
        self::assertFalse($repository->lastRefresh());
        $repository->all(refresh: true);
        self::assertFalse($repository->lastPublic());
        self::assertTrue($repository->lastRefresh());
        self::assertSame(2, $repository->calls());
    }

    public function testOrderRepositoryReturnsGivenOrdersForAnyCriteria(): void
    {
        $order = new Order('100', 1, OrderState::Confirmed, OrderType::Product, new Money('10', 'RUB'));
        $repository = new FakeOrderRepository([$order], ['заказ 101: неизвестный статус']);
        $week = new OrderCriteria(days: 7);
        $month = new OrderCriteria(days: 30, merchant: 2);

        $list = $repository->find($week);
        self::assertSame($week, $list->criteria());
        self::assertSame([$order], $list->all());
        self::assertSame(['заказ 101: неизвестный статус'], $list->skipped());
        self::assertSame([$order], $repository->find($month)->all());
        self::assertSame([$week, $month], $repository->criteria());
        self::assertTrue((new FakeOrderRepository())->find($week)->isEmpty());
    }

    public function testCouponFeedFiltersOnlyByMerchants(): void
    {
        $kind = new CouponKind(1, 'скидка');
        $first = self::coupon(1, 10, $kind);
        $second = self::coupon(2, 20, new CouponKind(2, 'подарок'));
        $feed = new FakeCouponFeed([$first, $second], [$kind], ['купон 3: нет ссылки']);

        $all = $feed->find(new CouponCriteria());
        self::assertSame([$first, $second], $all->all());
        self::assertSame([$kind], $all->kinds());
        self::assertSame(['купон 3: нет ссылки'], $all->skipped());

        $byMerchant = CouponCriteria::forMerchant(20);
        self::assertSame([$second], $feed->find($byMerchant)->all());
        self::assertSame([$first, $second], $feed->find(new CouponCriteria(kinds: [1]))->all());
        self::assertCount(3, $feed->criteria());
        self::assertSame($byMerchant, $feed->criteria()[1]);
    }

    public function testLostOrderClaimsFindGetSubmit(): void
    {
        $existing = new LostOrderClaim(5796, 'GS1', new \DateTimeImmutable('2026-09-24'), OrderTotal::of('1'), 2573, LostOrderStatus::Waiting, LostOrderClaimState::InWork);
        $other = new LostOrderClaim(5797, 'GS2', new \DateTimeImmutable('2026-09-24'), OrderTotal::of('2'), 99, LostOrderStatus::Declined, LostOrderClaimState::Closed);
        $claims = new FakeLostOrderClaims([$existing, $other], ['заявка 5798: неизвестный статус']);
        $byMerchant = new LostOrderCriteria(merchant: 2573);

        $found = $claims->find($byMerchant);
        self::assertSame([$existing], $found->all());
        self::assertSame(['заявка 5798: неизвестный статус'], $found->skipped());
        self::assertSame($other, $claims->get(new LostOrderClaimId(5797)));
        self::assertNull($claims->get(new LostOrderClaimId(1)));

        $new = new NewLostOrderClaim('GS3', '2026-09-25', '554.34', 2573, ClaimAttachment::fromContents('receipt.pdf', "%PDF-1.4\n"));
        $created = $claims->submit($new);
        self::assertSame(9001, $created->id()->value());
        self::assertSame('GS3', $created->orderNumber());
        self::assertSame(LostOrderStatus::Waiting, $created->orderStatus());
        self::assertSame([$new], $claims->submitted());
        self::assertSame($created, $claims->get(new LostOrderClaimId(9001)));
        self::assertSame([$existing, $created], $claims->find($byMerchant)->all());
        self::assertSame([$byMerchant, $byMerchant], $claims->criteria());

        $failure = new \RuntimeException('сбой');
        self::assertSame($claims, $claims->failSubmitWith($failure));
        try {
            $claims->submit($new);
            self::fail('Ожидалось исключение');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame([$new], $claims->submitted());
    }

    public function testSubmittedClaimGetsNextFreeId(): void
    {
        $existing = new LostOrderClaim(9001, 'GS1', new \DateTimeImmutable('2026-09-24'), OrderTotal::of('1'), 2573, LostOrderStatus::Waiting, LostOrderClaimState::InWork);
        $claims = new FakeLostOrderClaims([$existing]);
        $new = new NewLostOrderClaim('GS2', '2026-09-25', '1', 2573, ClaimAttachment::fromContents('receipt.pdf', "%PDF-1.4\n"));

        $created = $claims->submit($new);
        $next = $claims->submit($new);

        self::assertSame(9002, $created->id()->value());
        self::assertSame(9003, $next->id()->value());
        self::assertSame($created, $claims->get($created->id()));
        self::assertSame($existing, $claims->get(new LostOrderClaimId(9001)));
        $ids = array_map(static fn (LostOrderClaim $c): int => $c->id()->value(), $claims->find(new LostOrderCriteria())->all());
        self::assertSame([9001, 9002, 9003], $ids);
        self::assertSame(9001, (new FakeLostOrderClaims([new LostOrderClaim(5, 'GS5', new \DateTimeImmutable('2026-09-24'), OrderTotal::of('1'), 1, LostOrderStatus::Waiting, LostOrderClaimState::InWork)]))->submit($new)->id()->value());
    }

    public function testSubmittedClaimDateIsMoscowRegardlessOfDefaultTimezone(): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $created = (new FakeLostOrderClaims())->submit(new NewLostOrderClaim('GS2', '2026-09-25', '1', 2573, ClaimAttachment::fromContents('receipt.pdf', "%PDF-1.4\n")));
        } finally {
            date_default_timezone_set($default);
        }

        self::assertSame('2026-09-25 00:00:00 Europe/Moscow', $created->orderDate()->format('Y-m-d H:i:s e'));
    }

    private static function coupon(int $id, int $merchant, CouponKind $kind): Coupon
    {
        return new Coupon($id, $merchant, 'Купон ' . $id, $kind, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), 'https://f.gdeslon.ru/ck/0a1b2c3d4e/' . $id);
    }
}
