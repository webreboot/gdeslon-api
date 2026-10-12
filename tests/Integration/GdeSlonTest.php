<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
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
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Shared\DataOrigin;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Domain\Shared\SubId;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;
use Webreboot\GdeSlon\Testing\FakeCategoryRepository;
use Webreboot\GdeSlon\Testing\FakeCouponFeed;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Testing\FakeLostOrderClaims;
use Webreboot\GdeSlon\Testing\FakeMerchantRepository;
use Webreboot\GdeSlon\Testing\FakeOrderRepository;
use Webreboot\GdeSlon\Testing\FakeProductCatalog;
use Webreboot\GdeSlon\Testing\FrozenClock;
use Webreboot\GdeSlon\Testing\InMemoryCacheStore;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

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

        $gdeslon = new GdeSlon($transport, $repository);

        self::assertCount(0, $gdeslon->categories());
        self::assertFalse($repository->lastRefresh());
        self::assertCount(0, $gdeslon->categories(refresh: true));
        self::assertTrue($repository->lastRefresh());
        self::assertSame(2, $repository->calls());
        self::assertSame([], $transport->requests());
    }

    public function testCreateWiresCacheConfigAndTransport(): void
    {
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $store = new InMemoryCacheStore();
        $url = 'https://api.gdeslon.ru/gdeslon-categories.json';
        $store->set(DocumentCache::key($url), (new CachedDocument(Fixtures::read('categories/categories.json'), '"6724af75-26edb"', null, $clock->now()->modify('-3600 seconds')))->toString());
        $transport = (new FakeHttpTransport())->willReturn(304, '');

        $tree = GdeSlon::create(new Config(cacheTtl: 3600), $transport, $store, $clock)->categories();

        self::assertCount(23, $tree);
        self::assertSame('"6724af75-26edb"', $transport->lastRequest()->header('If-None-Match'));
    }

    public function testCacheTtlFromConfigDecidesFreshness(): void
    {
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $url = 'https://api.gdeslon.ru/gdeslon-categories.json';
        $entry = (new CachedDocument(Fixtures::read('categories/categories.json'), '"6724af75-26edb"', null, $clock->now()->modify('-2 hours')))->toString();

        foreach ([[7200, 1, DataOrigin::Revalidated], [7201, 0, DataOrigin::Cached]] as [$ttl, $expectedRequests, $origin]) {
            $store = new InMemoryCacheStore();
            $store->set(DocumentCache::key($url), $entry);
            $transport = (new FakeHttpTransport())->willReturn(304, '');

            $tree = GdeSlon::create(new Config(cacheTtl: $ttl), $transport, $store, $clock)->categories();

            self::assertCount($expectedRequests, $transport->requests(), 'cacheTtl ' . $ttl);
            self::assertSame($origin, $tree->origin(), 'cacheTtl ' . $ttl);
        }
    }

    public function testRefreshWithStaleCopyAndTimeoutThrowsAndKeepsCopy(): void
    {
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $store = new InMemoryCacheStore();
        $store->set(DocumentCache::key('https://api.gdeslon.ru/gdeslon-categories.json'), (new CachedDocument(Fixtures::read('categories/categories.json'), '"e"', null, $clock->now()->modify('-2 days')))->toString());
        $store->set(DocumentCache::key('https://www.gdeslon.ru/api/users/shops.xml#' . hash('sha256', 't')), (new CachedDocument(Fixtures::read('merchants/shops.xml'), 'W/"e"', null, $clock->now()->modify('-2 days')))->toString());
        $before = $store->all();
        $timeout = new TimeoutException('таймаут', 'GET', 'https://api.gdeslon.ru/', 28);
        $transport = (new FakeHttpTransport())->willThrow($timeout)->willThrow($timeout)->willThrow($timeout)->willThrow($timeout);
        $gdeslon = GdeSlon::create(new Config(apiToken: 't'), $transport, $store, $clock);

        foreach ([static fn () => $gdeslon->categories(refresh: true), static fn () => $gdeslon->merchants(refresh: true)] as $call) {
            try {
                $call();
                self::fail('Ожидалось исключение');
            } catch (TimeoutException $e) {
                self::assertSame($timeout, $e);
            }
        }
        self::assertSame($before, $store->all());

        $tree = $gdeslon->categories();
        self::assertCount(23, $tree);
        self::assertSame(DataOrigin::Stale, $tree->origin());
        $list = $gdeslon->merchants();
        self::assertCount(9, $list);
        self::assertSame(DataOrigin::Stale, $list->origin());
        self::assertSame($before, $store->all());
    }

    public function testCategoriesOriginThroughFacade(): void
    {
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $store = new InMemoryCacheStore();
        $store->set(DocumentCache::key('https://api.gdeslon.ru/gdeslon-categories.json'), (new CachedDocument(Fixtures::read('categories/categories.json'), '"e"', null, $clock->now()))->toString());
        $transport = new FakeHttpTransport();

        self::assertSame(DataOrigin::Cached, GdeSlon::create(new Config(), $transport, $store, $clock)->categories()->origin());
        self::assertSame([], $transport->requests());

        $repository = new FakeCategoryRepository((new CategoryTree([]))->withOrigin(DataOrigin::Stale));
        self::assertSame(DataOrigin::Stale, (new GdeSlon($transport, $repository))->categories()->origin());

        $merchants = new FakeMerchantRepository((new MerchantList([]))->withOrigin(DataOrigin::Revalidated));
        self::assertSame(DataOrigin::Revalidated, (new GdeSlon($transport, null, $merchants))->merchants(public: true)->origin());
    }

    public function testOwnTransportIsNotWrappedWithRange(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '{}');

        GdeSlon::create(transport: $transport)->categories();

        self::assertNull($transport->lastRequest()->header('Range'));
    }

    public function testFactoryTransportKeepsRangeThroughCreate(): void
    {
        $config = new Config();
        $inner = (new FakeHttpTransport())->willReturn(200, Fixtures::read('categories/categories.json'));

        $tree = GdeSlon::create($config, TransportFactory::fromConfig($config, $inner))->categories();

        self::assertCount(23, $tree);
        self::assertSame('bytes=0-16383', $inner->lastRequest()->header('Range'));
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $inner->lastRequest()->uri());
    }

    public function testMerchantsWithToken(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops.xml'));

        $list = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->merchants();

        self::assertCount(9, $list);
        self::assertSame('https://www.gdeslon.ru/api/users/shops.xml?api_token=secret-token', $transport->lastRequest()->uri());
    }

    public function testMerchantsWithoutTokenRequirePublicFlag(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops-public.xml'));
        $gdeslon = GdeSlon::create(new Config(), $transport);

        try {
            $gdeslon->merchants();
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Магазины вебмастера требуют токен XML API: new Config(apiToken: …); публичный каталог без партнёрских ссылок — merchants(public: true)', $e->getMessage());
        }
        self::assertSame([], $transport->requests());

        $list = $gdeslon->merchants(public: true);

        self::assertCount(2, $list);
        self::assertNull($list->get(105263)->affiliateLink());
        self::assertSame('https://www.gdeslon.ru/api/users/shops.xml', $transport->lastRequest()->uri());
    }

    public function testDefaultConstructorMerchantsRequireToken(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops-public.xml'));
        $gdeslon = new GdeSlon($transport);

        try {
            $gdeslon->merchants();
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('merchants(public: true)', $e->getMessage());
        }
        self::assertSame([], $transport->requests());

        self::assertCount(2, $gdeslon->merchants(public: true));
        self::assertSame('https://www.gdeslon.ru/api/users/shops.xml', $transport->lastRequest()->uri());
    }

    public function testPublicMerchantsWithTokenSkipToken(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops-public.xml'));

        $list = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->merchants(public: true);

        self::assertCount(2, $list);
        self::assertSame('https://www.gdeslon.ru/api/users/shops.xml', $transport->lastRequest()->uri());
    }

    public function testCustomMerchantRepository(): void
    {
        $transport = new FakeHttpTransport();
        $repository = new FakeMerchantRepository(new MerchantList([]));
        $gdeslon = new GdeSlon($transport, null, $repository);

        self::assertCount(0, $gdeslon->merchants());
        self::assertFalse($repository->lastPublic());
        self::assertFalse($repository->lastRefresh());
        self::assertCount(0, $gdeslon->merchants(public: true));
        self::assertTrue($repository->lastPublic());
        self::assertFalse($repository->lastRefresh());
        self::assertCount(0, $gdeslon->merchants(refresh: true));
        self::assertFalse($repository->lastPublic());
        self::assertTrue($repository->lastRefresh());
        self::assertCount(0, $gdeslon->merchants(public: true, refresh: true));
        self::assertTrue($repository->lastPublic());
        self::assertTrue($repository->lastRefresh());
        self::assertSame(4, $repository->calls());
        self::assertSame([], $transport->requests());
    }

    public function testPublicMerchantsReuseTokenlessCacheEntry(): void
    {
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $entry = (new CachedDocument(Fixtures::read('merchants/shops-public.xml'), 'W/"e"', null, $clock->now()))->toString();

        foreach ([null, 'secret-token'] as $token) {
            $store = new InMemoryCacheStore();
            $store->set(DocumentCache::key('https://www.gdeslon.ru/api/users/shops.xml#' . hash('sha256', '')), $entry);
            $transport = new FakeHttpTransport();

            $list = GdeSlon::create(new Config(apiToken: $token), $transport, $store, $clock)->merchants(public: true);

            self::assertCount(2, $list);
            self::assertSame([], $transport->requests());
        }
    }

    public function testMerchantCacheTtlAndKeyFromConfig(): void
    {
        $clock = FrozenClock::at('2026-10-07 12:00:00');
        $url = 'https://www.gdeslon.ru/api/users/shops.xml';
        $entry = (new CachedDocument(Fixtures::read('merchants/shops.xml'), 'W/"e"', null, $clock->now()->modify('-2 hours')))->toString();

        foreach ([[7200, 1, DataOrigin::Revalidated], [7201, 0, DataOrigin::Cached]] as [$ttl, $expectedRequests, $origin]) {
            $store = new InMemoryCacheStore();
            $store->set(DocumentCache::key($url . '#' . hash('sha256', 't')), $entry);
            $transport = (new FakeHttpTransport())->willReturn(304, '');

            $list = GdeSlon::create(new Config(apiToken: 't', merchantCacheTtl: $ttl), $transport, $store, $clock)->merchants();

            self::assertCount(9, $list);
            self::assertCount($expectedRequests, $transport->requests(), 'merchantCacheTtl ' . $ttl);
            self::assertSame($origin, $list->origin(), 'merchantCacheTtl ' . $ttl);
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

    public function testSleepListsEveryProperty(): void
    {
        $properties = array_map(static fn (\ReflectionProperty $p): string => $p->name, (new \ReflectionClass(GdeSlon::class))->getProperties());
        $sleep = GdeSlon::create(new Config(), new FakeHttpTransport())->__sleep();

        self::assertEqualsCanonicalizing($properties, $sleep);
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

        self::assertCount(7, $result);
        self::assertCount(1, $result->skipped());
        self::assertSame('secret-token', $transport->lastRequest()->query()['_gs_at'] ?? null);
        self::assertSame('iphone -pink', $transport->lastRequest()->query()['q'] ?? null);
        self::assertSame('iphone -pink', $result->criteria()->query());
    }

    public function testOfferLinkWithSubIdFromSearch(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search.xml'));

        $offer = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->search('пакет')->offers()[0];

        $url = 'https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=107054&goto=https%3A%2F%2Famwine.ru%2Fcatalog%2Faksessuary%2Fpodarochnye_pakety_i_korobki%2Fpaket_podarochnyy_m_180_227_100%2F&erid=2SDnjcZqvUG';
        self::assertSame($url . '&sub_id=tg_channel-42', (string) $offer->link()->withSubId('tg_channel-42'));
        self::assertSame($url . '&sub_id=tg_channel-42&sub_id2=post-7', $offer->link()->withSubId('tg_channel-42')->withSubId(new SubId('post-7'), 2)->url());
        self::assertSame($url, $offer->affiliateLink());
        self::assertSame('2SDnjcZqvUG', $offer->erid());
        self::assertSame('107054:578237', (string) $offer->key());
    }

    public function testSearchBySubtreeSendsWholeSubtree(): void
    {
        $transport = (new FakeHttpTransport())
            ->willReturn(200, Fixtures::read('categories/categories.json'))
            ->willReturn(200, Fixtures::read('search/search-empty.xml'));
        $gdeslon = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport);

        $tree = $gdeslon->categories();
        $result = $gdeslon->search(new SearchCriteria(
            query: 'тренч',
            categories: [new CategoryId(1113), ...array_map(static fn (Category $c): CategoryId => $c->id(), $tree->descendantsOf(1113))],
        ));

        self::assertTrue($result->isEmpty());
        self::assertCount(2, $transport->requests());
        self::assertSame('1113,1114,1128,1216,1217,1230,1231,1232,1522', $transport->lastRequest()->query()['tid'] ?? null);
        self::assertArrayNotHasKey('no_tid', $transport->lastRequest()->query());
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

    public function testSearchSplitsTimedOutPageByDefault(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willReturn(200, Fixtures::read('search/search-l5-p1.xml'))
            ->willReturn(200, Fixtures::read('search/search-l5-p2.xml'));

        $result = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->search('платье');

        self::assertCount(3, $transport->requests());
        self::assertCount(10, $result);
        self::assertTrue($result->isDegraded());
        self::assertSame(5, $result->effectiveLimit());
        self::assertSame(10, $result->criteria()->limit());
    }

    public function testSearchDoesNotSplitOnConnectTimeout(): void
    {
        $transport = (new FakeHttpTransport())->willTimeOutOnConnect();

        try {
            GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->search('платье');
            self::fail('Ожидалось исключение');
        } catch (ConnectTimeoutException $e) {
            self::assertStringContainsString(': превышено время установки соединения (cURL 28: Connection timed out after 10000 milliseconds)', $e->getMessage());
            self::assertStringNotContainsString('16 КБ', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->getMessage() . $e->url());
            self::assertNull($e->getPrevious());
        }
        self::assertCount(1, $transport->requests());
    }

    public function testSearchSplitsTimedOutPartAgainByDefault(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willFail(28, 'Operation timed out');
        for ($page = 1; $page <= 5; $page++) {
            $transport->willReturn(200, Fixtures::read(sprintf('search/search-l1-p%d.xml', $page)));
        }
        $transport->willReturn(200, Fixtures::read('search/search-l5-p2.xml'));

        $result = GdeSlon::create(new Config(apiToken: 'secret-token'), $transport)->search('платье');

        self::assertSame([10, 5, 1, 1, 1, 1, 1, 5], array_map(static fn (HttpRequest $r): mixed => $r->query()['l'] ?? null, $transport->requests()));
        self::assertCount(10, $result);
        self::assertSame(1, $result->effectiveLimit());
    }

    public function testSearchRetriesConnectTimeoutThroughFactoryTransport(): void
    {
        $config = new Config(apiToken: 'secret-token');
        $inner = (new FakeHttpTransport())->willTimeOutOnConnect()->willReturn(200, Fixtures::read('search/search-l5-p1.xml'));

        $result = GdeSlon::create($config, TransportFactory::fromConfig($config, inner: $inner))->search(new SearchCriteria(query: 'платье', limit: 5));

        self::assertCount(5, $result);
        self::assertCount(2, $inner->requests());
    }

    public function testLostOrderSubmitIsNotRetriedOnConnectTimeout(): void
    {
        $config = new Config(apiToken: 'secret-token', connectRetries: 5);
        $inner = (new FakeHttpTransport())->willTimeOutOnConnect();
        $gdeslon = GdeSlon::create($config, TransportFactory::fromConfig($config, inner: $inner));

        try {
            $gdeslon->submitLostOrderClaim(self::newLostOrderClaim('GS123L'), checkDuplicates: false);
            self::fail('Ожидалось исключение');
        } catch (LostOrderClaimUnconfirmedException) {
        }
        self::assertCount(1, $inner->requests());
        self::assertSame('POST', $inner->lastRequest()->method());
    }

    public function testSearchFallbackLimitFromConfig(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willReturn(200, Fixtures::read('search/search-empty.xml'));

        $result = GdeSlon::create(new Config(apiToken: 'secret-token', searchFallbackLimit: 2), $transport)->search('платье');

        self::assertSame([10, 2], array_map(static fn (HttpRequest $r): mixed => $r->query()['l'] ?? null, $transport->requests()));
        self::assertSame(2, $result->effectiveLimit());
        self::assertTrue($result->isEmpty());
    }

    public function testSearchFallbackCanBeDisabled(): void
    {
        $transport = (new FakeHttpTransport())->willFail(28, 'Operation timed out');
        $gdeslon = GdeSlon::create(new Config(apiToken: 'secret-token', searchFallbackLimit: null), $transport);

        try {
            $gdeslon->search('платье');
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertStringEndsWith('уменьшите limit (сейчас 10)', $e->getMessage());
        }
        self::assertCount(1, $transport->requests());
    }

    public function testCustomProductCatalogIsNotWrapped(): void
    {
        $timeout = new TimeoutException('превышено время ожидания', 'GET', 'https://example.com/search', 28);
        $catalog = new FakeProductCatalog([static fn (): SearchResult => throw $timeout]);
        $gdeslon = new GdeSlon(new FakeHttpTransport(), null, null, $catalog);

        try {
            $gdeslon->search('платье');
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e);
        }
        self::assertCount(1, $catalog->criteria());
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
        self::assertSame($criteria, $claims->criteria()[0]);
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
        self::assertSame([], $claims->submitted());
        self::assertSame(2573, $claims->criteria()[0]->merchant()?->value(), 'проверка по своему магазину');

        $created = $gdeslon->submitLostOrderClaim(self::newLostOrderClaim('GS999'));
        self::assertSame('GS999', $created->orderNumber());
        self::assertCount(1, $claims->submitted());

        $gdeslon->submitLostOrderClaim(self::newLostOrderClaim('GS123L'), checkDuplicates: false);
        self::assertCount(2, $claims->submitted());
        self::assertCount(2, $claims->criteria(), 'без проверки — без запроса списка');
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
        self::assertSame([], $claims->submitted());
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
        self::assertSame($criteria, $feed->criteria()[0]);
        $gdeslon->coupons();
        self::assertEquals(new CouponCriteria(), $feed->criteria()[1]);
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
