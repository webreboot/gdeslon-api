<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\OfferParam;
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
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownloadStatus;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedValidators;
use Webreboot\GdeSlon\Testing\FakeCategoryRepository;
use Webreboot\GdeSlon\Testing\FakeFeedDownloader;
use Webreboot\GdeSlon\Testing\FakeCouponFeed;
use Webreboot\GdeSlon\Testing\FakeLostOrderClaims;
use Webreboot\GdeSlon\Testing\FakeMerchantRepository;
use Webreboot\GdeSlon\Testing\FakeOrderRepository;
use Webreboot\GdeSlon\Testing\FakeProductCatalog;
use Webreboot\GdeSlon\Testing\FakeProductFeed;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

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

    public function testProductFeedFromObjects(): void
    {
        $offer = new Offer('1', new MerchantId(1), 'Платье', new Money('1499.00', 'RUB'), 'https://xf.gdeslon.ru/cm/0a1b2c3d4e/?mid=1', params: [new OfferParam('Цвет', 'Серый')]);
        $tree = new CategoryTree([]);
        $date = new \DateTimeImmutable('2026-10-13 21:50', new \DateTimeZone('Europe/Moscow'));
        $feed = new FakeProductFeed([$offer], $tree, $date, ['оффер «2»: цена 0, оффер пропущен']);

        self::assertSame([$offer], iterator_to_array($feed->offers(), false));
        self::assertSame([$offer], iterator_to_array($feed->offers(), false));
        self::assertSame(2, $feed->traversals());
        self::assertSame($tree, $feed->categories());
        self::assertSame($date, $feed->generatedAt());
        self::assertSame(['оффер «2»: цена 0, оффер пропущен'], $feed->skipped());
        self::assertSame(1, $feed->skippedCount());
    }

    public function testProductFeedDefaultsAreEmpty(): void
    {
        $feed = new FakeProductFeed();

        self::assertSame([], iterator_to_array($feed->offers(), false));
        self::assertCount(0, $feed->categories());
        self::assertNull($feed->generatedAt());
        self::assertSame(0, $feed->skippedCount());
    }

    public function testProductFeedFromYmlMatchesRealReader(): void
    {
        $feed = FakeProductFeed::fromYml(Fixtures::read('feeds/feed.xml'));

        $offers = iterator_to_array($feed->offers(), false);
        self::assertCount(21, $offers);
        self::assertSame('86276:3080332', (string) $offers[0]->key());
        self::assertSame('Серый', $offers[0]->param('Цвет'));
        self::assertCount(27, $feed->categories());
        self::assertSame('2026-10-13 21:50', $feed->generatedAt()?->format('Y-m-d H:i'));
        self::assertSame(1, $feed->skippedCount());
    }

    public function testProductFeedFromYmlKeepsFullSkippedCount(): void
    {
        $offers = '';
        for ($i = 1; $i <= 151; $i++) {
            $offers .= sprintf(
                '<offer id="%d" merchant_id="1" article="a%d" gs_category_id="1144"><name>x</name><url>https://xf.gdeslon.ru/cm/0a1b2c3d4e/?mid=1</url><currencyId>RUR</currencyId><categoryId>1144</categoryId><price>%s</price></offer>',
                $i,
                $i,
                $i === 151 ? '10.00' : '0.00',
            );
        }

        $feed = FakeProductFeed::fromYml('<yml_catalog><shop><offers>' . $offers . '</offers></shop></yml_catalog>');

        self::assertSame(150, $feed->skippedCount());
        self::assertCount(100, $feed->skipped());
        self::assertSame(3, (new FakeProductFeed(skipped: ['a', 'b'], skippedCount: 3))->skippedCount());
        self::assertSame(2, (new FakeProductFeed(skipped: ['a', 'b']))->skippedCount());
    }

    public function testFeedDownloaderQueuesResultsAndWritesTarget(): void
    {
        $dir = sys_get_temp_dir() . '/gdeslon-fake-download-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $url = FeedUrl::fromString('https://export.gdeslon.ru/uploads/exports/Zk3secret0123456789.zip');
        $validators = new FeedValidators('"v1"', 'Wed, 14 Oct 2026 09:48:14 GMT');
        $downloader = (new FakeFeedDownloader())
            ->willDownload('<yml_catalog/>', $validators)
            ->willReturnNotModified()
            ->willThrow(new TimeoutException('таймаут', 'GET', $url->masked(), 28));

        try {
            $first = $downloader->download($url, $dir . '/feed.xml');
            self::assertSame(FeedDownloadStatus::Downloaded, $first->status());
            self::assertSame('<yml_catalog/>', file_get_contents($dir . '/feed.xml'));
            self::assertSame(14, $first->bytes());
            self::assertSame($validators, $first->validators());

            $second = $downloader->download($url, $dir . '/feed.xml', $validators);
            self::assertTrue($second->isNotModified());
            self::assertSame($validators->toArray(), $second->validators()->toArray());

            try {
                $downloader->download($url, $dir . '/feed.xml');
                self::fail('ожидалось исключение');
            } catch (TimeoutException) {
            }

            self::assertSame(
                [
                    ['url' => 'https://export.gdeslon.ru/uploads/exports/***', 'target' => $dir . '/feed.xml', 'previous' => null],
                    ['url' => 'https://export.gdeslon.ru/uploads/exports/***', 'target' => $dir . '/feed.xml', 'previous' => $validators],
                    ['url' => 'https://export.gdeslon.ru/uploads/exports/***', 'target' => $dir . '/feed.xml', 'previous' => null],
                ],
                $downloader->downloads(),
            );

            $this->expectException(\LogicException::class);
            $downloader->download($url, $dir . '/feed.xml');
        } finally {
            @unlink($dir . '/feed.xml');
            rmdir($dir);
        }
    }

    public function testProductFeedFromYmlRejectsBrokenFeed(): void
    {
        $this->expectException(UnexpectedResponseException::class);

        FakeProductFeed::fromYml('<html/>');
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
