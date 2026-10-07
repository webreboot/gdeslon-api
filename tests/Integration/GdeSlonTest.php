<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Tests\Support\FakeCategoryRepository;
use Webreboot\GdeSlon\Tests\Support\FakeCouponFeed;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\FakeLostOrderClaims;
use Webreboot\GdeSlon\Tests\Support\FakeMerchantRepository;
use Webreboot\GdeSlon\Tests\Support\FakeOrderRepository;
use Webreboot\GdeSlon\Tests\Support\FakeProductCatalog;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;
use Webreboot\GdeSlon\Tests\Support\InMemoryCacheStore;

final class GdeSlonTest extends TestCase
{
    public function testCategories(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('categories/categories.json'));

        $tree = GdeSlon::create(transport: $transport)->categories();

        self::assertCount(23, $tree);
        self::assertSame('Парки', $tree->get(1231)->name());
        self::assertCount(1, $transport->requests());
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $transport->lastRequest()->uri());
    }

    public function testEachCallLoadsFreshData(): void
    {
        $transport = (new FakeHttpTransport())
            ->willReturn(200, Fixtures::read('categories/categories.json'))
            ->willReturn(200, '{}');
        $gdeslon = new GdeSlon($transport);

        self::assertCount(23, $gdeslon->categories());
        self::assertCount(0, $gdeslon->categories());
    }

    public function testCreateDoesNotTouchNetwork(): void
    {
        $transport = new FakeHttpTransport();

        GdeSlon::create(new Config(timeout: 60.0), $transport);

        self::assertSame([], $transport->requests());
    }

    public function testCustomCategoryRepository(): void
    {
        $transport = new FakeHttpTransport();
        $repository = new FakeCategoryRepository(new CategoryTree([]));

        $tree = (new GdeSlon($transport, $repository))->categories();

        self::assertCount(0, $tree);
        self::assertSame(1, $repository->calls());
        self::assertSame([], $transport->requests());
    }

    public function testCreateWiresCacheConfigAndTransport(): void
    {
        $store = new InMemoryCacheStore();
        $url = 'https://api.gdeslon.ru/gdeslon-categories.json';
        $storedAt = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
        $store->set(DocumentCache::key($url), (new CachedDocument(Fixtures::read('categories/categories.json'), '"6724af75-26edb"', null, $storedAt))->toString());
        $transport = (new FakeHttpTransport())->willReturn(304, '');

        $tree = GdeSlon::create(new Config(cacheTtl: 3600), $transport, $store)->categories();

        self::assertCount(23, $tree);
        self::assertSame('"6724af75-26edb"', $transport->lastRequest()->header('If-None-Match'));
    }

    public function testCacheTtlFromConfigDecidesFreshness(): void
    {
        $url = 'https://api.gdeslon.ru/gdeslon-categories.json';
        $twoHoursAgo = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $entry = (new CachedDocument(Fixtures::read('categories/categories.json'), '"6724af75-26edb"', null, $twoHoursAgo))->toString();

        foreach ([3600 => 1, 86400 => 0] as $ttl => $expectedRequests) {
            $store = new InMemoryCacheStore();
            $store->set(DocumentCache::key($url), $entry);
            $transport = (new FakeHttpTransport())->willReturn(304, '');

            GdeSlon::create(new Config(cacheTtl: $ttl), $transport, $store)->categories();

            self::assertCount($expectedRequests, $transport->requests(), 'cacheTtl ' . $ttl);
        }
    }

    public function testOwnTransportIsNotWrappedWithRange(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '{}');

        GdeSlon::create(transport: $transport)->categories();

        self::assertNull($transport->lastRequest()->header('Range'));
    }

    public function testMerchantsWithToken(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops.xml'));

        $list = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->merchants();

        self::assertCount(9, $list);
        self::assertSame('https://www.gdeslon.ru/api/users/shops.xml?api_token=secret-token', $transport->lastRequest()->uri());
    }

    public function testCustomMerchantRepository(): void
    {
        $transport = new FakeHttpTransport();
        $repository = new FakeMerchantRepository(new MerchantList([]));

        self::assertCount(0, (new GdeSlon($transport, null, $repository))->merchants());
        self::assertSame(1, $repository->calls());
        self::assertSame([], $transport->requests());
    }

    public function testMerchantCacheTtlAndKeyFromConfig(): void
    {
        $url = 'https://www.gdeslon.ru/api/users/shops.xml';
        $twoHoursAgo = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $entry = (new CachedDocument(Fixtures::read('merchants/shops.xml'), 'W/"e"', null, $twoHoursAgo))->toString();

        foreach ([3600 => 1, 86400 => 0] as $ttl => $expectedRequests) {
            $store = new InMemoryCacheStore();
            $store->set(DocumentCache::key($url . '#' . hash('sha256', 't')), $entry);
            $transport = (new FakeHttpTransport())->willReturn(304, '');

            $list = GdeSlon::create(new Config(apiToken: 't', merchantCacheTtl: $ttl), $transport, $store)->merchants();

            self::assertCount(9, $list);
            self::assertCount($expectedRequests, $transport->requests(), 'merchantCacheTtl ' . $ttl);
        }
    }

    public function testApiTokenIsHiddenFromFacadeDumps(): void
    {
        $gdeslon = GdeSlon::create(new Config(apiToken: 'secret-token'), new FakeHttpTransport());

        ob_start();
        var_dump($gdeslon);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('secret-token', print_r($gdeslon, true));
        self::assertStringNotContainsString('secret-token', $dump);
    }

    public function testClientWithTokenIsNotSerializable(): void
    {
        self::assertInstanceOf(GdeSlon::class, unserialize(serialize(GdeSlon::create(new Config(), new FakeHttpTransport()))));

        try {
            serialize(GdeSlon::create(new Config(apiToken: 'secret-token'), new FakeHttpTransport()));
            self::fail('Ожидалось исключение');
        } catch (GdeSlonException $e) {
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testSearch(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search.xml'));

        $result = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->search('iphone -pink');

        self::assertCount(8, $result);
        self::assertSame('secret-token', $transport->lastRequest()->query()['_gs_at'] ?? null);
        self::assertSame('iphone -pink', $transport->lastRequest()->query()['q'] ?? null);
        self::assertSame('iphone -pink', $result->criteria()->query());
    }

    public function testCustomProductCatalog(): void
    {
        $transport = new FakeHttpTransport();
        $catalog = new FakeProductCatalog([
            static fn (SearchCriteria $c): SearchResult => new SearchResult($c, [], 0),
            static fn (SearchCriteria $c): SearchResult => new SearchResult($c, [], 0),
        ]);
        $gdeslon = new GdeSlon($transport, null, null, $catalog);
        $criteria = new SearchCriteria(query: 'платье', limit: 5);

        $gdeslon->search($criteria);
        $gdeslon->search('x');

        self::assertSame($criteria, $catalog->criteria()[0]);
        self::assertSame('x', $catalog->criteria()[1]->query());
        self::assertSame([], $transport->requests());
    }

    public function testPaginationLoopStopsOnLastPage(): void
    {
        $offer = static fn (string $id): Offer => new Offer($id, new MerchantId(1), 'Товар', new Money('1', 'RUR'), 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1');
        $catalog = new FakeProductCatalog([
            static fn (SearchCriteria $c): SearchResult => new SearchResult($c, [$offer('1'), $offer('2')], 5),
            static fn (SearchCriteria $c): SearchResult => new SearchResult($c, [$offer('3'), $offer('4')], 5),
            static fn (SearchCriteria $c): SearchResult => new SearchResult($c, [$offer('5')], 5),
        ]);
        $gdeslon = new GdeSlon(new FakeHttpTransport(), null, null, $catalog);

        $pages = [];
        for ($page = $gdeslon->search(new SearchCriteria(limit: 2)); ; $page = $gdeslon->search($next)) {
            $pages[] = $page->criteria()->page();
            $next = $page->nextPage();
            if ($next === null) {
                break;
            }
        }

        self::assertSame([1, 2, 3], $pages);
    }

    public function testDefaultUserAgentContainsPackageVersion(): void
    {
        self::assertStringContainsString('webreboot-gdeslon-api/' . GdeSlon::VERSION, CurlTransport::defaultUserAgent());
    }

    public function testOrders(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('orders/orders-synthetic.json'));

        $orders = GdeSlon::create(new Config(userId: 1234, apiKey: 'test-api-key'), $transport)->orders(new OrderCriteria(until: '2026-10-07'));

        self::assertCount(4, $orders);
        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method());
        self::assertSame('https://gdeslon.ru/api/orders/', $request->uri());
        self::assertSame('Basic ' . base64_encode('1234:test-api-key'), $request->header('Authorization'));
        self::assertSame('{"created_at":{"date":"2026-10-07","period":30}}', $request->body());
    }

    public function testCustomOrderRepository(): void
    {
        $transport = new FakeHttpTransport();
        $repository = new FakeOrderRepository();
        $gdeslon = new GdeSlon($transport, null, null, null, $repository);
        $criteria = new OrderCriteria(days: 7);

        self::assertSame($criteria, $gdeslon->orders($criteria)->criteria());
        $gdeslon->orders();

        self::assertSame($criteria, $repository->criteria()[0]);
        self::assertEquals(new OrderCriteria(), $repository->criteria()[1]);
        self::assertSame([], $transport->requests());
    }

    public function testOrdersRequireSalesCredentials(): void
    {
        $transport = new FakeHttpTransport();

        try {
            GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->orders();
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('apiKey', $e->getMessage());
        }
        self::assertSame([], $transport->requests());
    }

    public function testSalesKeyIsHiddenInFacade(): void
    {
        $gdeslon = GdeSlon::create(new Config(userId: 1234, apiKey: 'test-api-key'), new FakeHttpTransport());

        ob_start();
        var_dump($gdeslon);
        $dump = (string) ob_get_clean() . print_r($gdeslon, true);
        self::assertStringNotContainsString('test-api-key', $dump);

        try {
            serialize($gdeslon);
            self::fail('Ожидалось исключение');
        } catch (GdeSlonException $e) {
            self::assertStringNotContainsString('test-api-key', $e->getMessage());
        }
    }

    public function testLostOrdersDelegateToPort(): void
    {
        $claims = new FakeLostOrderClaims([self::lostOrderClaim(5796, 'GS123L', 2573)]);
        $gdeslon = new GdeSlon(new FakeHttpTransport(), null, null, null, null, $claims, FrozenClock::at('2026-10-07T10:00:00Z'));
        $criteria = new LostOrderCriteria(merchant: 2573);

        self::assertCount(1, $gdeslon->lostOrders($criteria));
        self::assertSame($criteria, $claims->criteria[0]);
        self::assertSame(5796, $gdeslon->lostOrder(5796)?->id()->value());
        self::assertNull($gdeslon->lostOrder(new LostOrderClaimId(1)));
        self::assertCount(1, $gdeslon->lostOrders());
    }

    public function testSubmitChecksDuplicates(): void
    {
        $claims = new FakeLostOrderClaims([self::lostOrderClaim(5796, 'GS123L', 2573)]);
        $gdeslon = new GdeSlon(new FakeHttpTransport(), null, null, null, null, $claims, FrozenClock::at('2026-10-07T10:00:00Z'));

        try {
            $gdeslon->submitLostOrderClaim(self::newLostOrderClaim(' gs123l '));
            self::fail('Ожидалось исключение');
        } catch (DuplicateLostOrderClaimException $e) {
            self::assertSame(5796, $e->existing()->id()->value());
        }
        self::assertSame([], $claims->submitted);
        self::assertSame(2573, $claims->criteria[0]->merchant()?->value(), 'проверка по своему магазину');

        $created = $gdeslon->submitLostOrderClaim(self::newLostOrderClaim('GS999'));
        self::assertSame('GS999', $created->orderNumber());
        self::assertCount(1, $claims->submitted);

        $gdeslon->submitLostOrderClaim(self::newLostOrderClaim('GS123L'), checkDuplicates: false);
        self::assertCount(2, $claims->submitted);
        self::assertCount(2, $claims->criteria, 'без проверки — без запроса списка');
    }

    public function testSubmitStopsWhenDuplicatesCannotBeChecked(): void
    {
        $claims = new FakeLostOrderClaims([self::lostOrderClaim(1, 'X', 2573)], ['заявка #2 (5797): order_status: неизвестный статус']);
        $gdeslon = new GdeSlon(new FakeHttpTransport(), null, null, null, null, $claims, FrozenClock::at('2026-10-07T10:00:00Z'));

        try {
            $gdeslon->submitLostOrderClaim(self::newLostOrderClaim('GS123L'));
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('дубли', $e->getMessage());
        }
        self::assertSame([], $claims->submitted);
    }

    public function testCreateWiresLostOrdersWithToken(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '[]');

        GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->lostOrders();

        self::assertSame('Bearer secret-token', $transport->lastRequest()->header('Authorization'));
        self::assertSame('https://gdeslon.ru/api/v1/lost-orders/', $transport->lastRequest()->uri());

        $offline = new FakeHttpTransport();
        try {
            GdeSlon::create(new Config(), $offline)->lostOrders();
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException) {
            self::assertSame([], $offline->requests());
        }
    }

    private static function lostOrderClaim(int $id, string $number, int $merchant): LostOrderClaim
    {
        return new LostOrderClaim($id, $number, new \DateTimeImmutable('2026-09-24'), OrderTotal::of('1'), $merchant, LostOrderStatus::Waiting, LostOrderClaimState::InWork);
    }

    private static function newLostOrderClaim(string $number): NewLostOrderClaim
    {
        return new NewLostOrderClaim($number, '2026-09-24', '554.34', 2573, ClaimAttachment::fromContents('receipt.pdf', "%PDF-1.4\n"));
    }

    public function testCouponsDelegateToPort(): void
    {
        $feed = new FakeCouponFeed();
        $gdeslon = new GdeSlon(new FakeHttpTransport(), null, null, null, null, null, null, $feed);
        $criteria = CouponCriteria::forMerchant(99157);

        self::assertSame($criteria, $gdeslon->coupons($criteria)->criteria());
        self::assertSame($criteria, $feed->lastCriteria);
        $gdeslon->coupons();
        self::assertEquals(new CouponCriteria(), $feed->lastCriteria);
    }

    public function testCreateWiresCouponsWithToken(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons.xml'));

        $coupons = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->coupons();

        self::assertCount(7, $coupons);
        self::assertSame('https://gdeslon.ru/api/coupons.xml?api_token=secret-token', $transport->lastRequest()->uri());

        $offline = new FakeHttpTransport();
        try {
            GdeSlon::create(new Config(), $offline)->coupons();
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException) {
            self::assertSame([], $offline->requests());
        }
    }

    public function testOrderDateWindowIsCheckedBeforeDuplicateLookup(): void
    {
        $transport = new FakeHttpTransport();
        $gdeslon = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport, clock: FrozenClock::at('2026-10-07T10:00:00Z'));

        try {
            $gdeslon->submitLostOrderClaim(new NewLostOrderClaim('GS123L', '2026-01-01', '1', 2573, ClaimAttachment::fromContents('r.pdf', "%PDF-1.4\n")));
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('2026-01-01', $e->getMessage());
        }
        self::assertSame([], $transport->requests());
    }
}
