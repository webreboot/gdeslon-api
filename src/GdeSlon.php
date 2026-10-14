<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon;

use Webreboot\GdeSlon\Domain\Catalog\CategoryRepository;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\MerchantRepository;
use Webreboot\GdeSlon\Domain\Catalog\ProductCatalog;
use Webreboot\GdeSlon\Domain\Catalog\ProductFeed;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaims;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteriaRejectedException;
use Webreboot\GdeSlon\Domain\Promo\CouponFeed;
use Webreboot\GdeSlon\Domain\Promo\CouponList;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Sales\OrderRepository;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpCategoryRepository;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpMerchantRepository;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpProductCatalog;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\SplitOnTimeoutProductCatalog;
use Webreboot\GdeSlon\Infrastructure\Api\Claims\HttpLostOrderClaims;
use Webreboot\GdeSlon\Infrastructure\Api\Promo\HttpCouponFeed;
use Webreboot\GdeSlon\Infrastructure\Api\Sales\HttpOrderRepository;
use Webreboot\GdeSlon\Infrastructure\Cache\CacheStore;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Infrastructure\Clock\SystemClock;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownload;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownloader;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedValidators;
use Webreboot\GdeSlon\Infrastructure\Feed\RangedFeedDownloader;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;
use Webreboot\GdeSlon\Infrastructure\Yml\YmlProductFeed;
use Webreboot\GdeSlon\Infrastructure\Yml\ZipEntryExtractor;

final class GdeSlon
{
    public const VERSION = '0.5.0';

    private readonly CategoryRepository $categories;

    private readonly MerchantRepository $merchants;

    private readonly ProductCatalog $products;

    private readonly OrderRepository $orders;

    private readonly LostOrderClaims $lostOrders;

    private readonly Clock $clock;

    private readonly CouponFeed $coupons;

    private readonly FeedDownloader $feedDownloader;

    public function __construct(
        HttpTransport $transport,
        ?CategoryRepository $categories = null,
        ?MerchantRepository $merchants = null,
        ?ProductCatalog $products = null,
        ?OrderRepository $orders = null,
        ?LostOrderClaims $lostOrders = null,
        ?Clock $clock = null,
        ?CouponFeed $coupons = null,
        ?FeedDownloader $feedDownloader = null,
    )
    {
        $client = new ApiClient($transport);
        $this->categories = $categories ?? new HttpCategoryRepository($client);
        $this->merchants = $merchants ?? new HttpMerchantRepository($client);
        $this->products = $products ?? new SplitOnTimeoutProductCatalog(new HttpProductCatalog($client));
        $this->orders = $orders ?? new HttpOrderRepository($client);
        $this->lostOrders = $lostOrders ?? new HttpLostOrderClaims($client);
        $this->clock = $clock ?? new SystemClock();
        $this->coupons = $coupons ?? new HttpCouponFeed($client);
        $this->feedDownloader = $feedDownloader ?? RangedFeedDownloader::create();
    }

    public static function create(#[\SensitiveParameter] ?Config $config = null, ?HttpTransport $transport = null, ?CacheStore $cache = null, ?Clock $clock = null, ?FeedDownloader $feedDownloader = null): self
    {
        $config ??= new Config();
        $transport ??= TransportFactory::fromConfig($config);
        $client = new ApiClient($transport);
        $clock ??= new SystemClock();

        return new self(
            $transport,
            new HttpCategoryRepository(
                $client,
                HttpCategoryRepository::DEFAULT_URL,
                $cache === null ? null : new DocumentCache($cache, $clock, $config->cacheTtl()),
            ),
            new HttpMerchantRepository(
                $client,
                $config->apiToken(),
                $cache === null ? null : new DocumentCache($cache, $clock, $config->merchantCacheTtl()),
            ),
            new SplitOnTimeoutProductCatalog(new HttpProductCatalog($client, $config->apiToken()), $config->searchFallbackLimit()),
            new HttpOrderRepository($client, $config->userId(), $config->apiKey(), $clock),
            new HttpLostOrderClaims($client, $config->apiToken(), $clock),
            $clock,
            new HttpCouponFeed($client, $config->apiToken()),
            $feedDownloader ?? RangedFeedDownloader::create($config),
        );
    }

    /**
     * @throws GdeSlonException
     */
    public function categories(bool $refresh = false): CategoryTree
    {
        return $this->categories->all(refresh: $refresh);
    }

    /**
     * @throws GdeSlonException
     */
    public function openFeed(string $path): ProductFeed
    {
        return YmlProductFeed::open($path);
    }

    /**
     * @throws GdeSlonException
     */
    public function downloadFeed(#[\SensitiveParameter] FeedUrl $url, string $xmlPath, ?FeedValidators $previous = null): FeedDownload
    {
        return $this->feedDownloader->download($url, $xmlPath, $previous);
    }

    /**
     * @throws GdeSlonException
     */
    public function unpackFeed(string $zipPath, string $xmlPath): int
    {
        return (new ZipEntryExtractor())->extract($zipPath, $xmlPath);
    }

    /**
     * @throws GdeSlonException
     */
    public function search(SearchCriteria|string $criteria = new SearchCriteria()): SearchResult
    {
        return $this->products->search(is_string($criteria) ? new SearchCriteria(query: $criteria) : $criteria);
    }

    /**
     * @throws GdeSlonException
     */
    public function merchants(bool $public = false, bool $refresh = false): MerchantList
    {
        return $this->merchants->all(public: $public, refresh: $refresh);
    }

    /**
     * @throws GdeSlonException
     */
    public function orders(#[\SensitiveParameter] OrderCriteria $criteria = new OrderCriteria()): OrderList
    {
        return $this->orders->find($criteria);
    }

    /**
     * @throws GdeSlonException
     */
    public function lostOrders(LostOrderCriteria $criteria = new LostOrderCriteria()): LostOrderClaimList
    {
        return $this->lostOrders->find($criteria);
    }

    /**
     * @throws GdeSlonException
     */
    public function lostOrder(int|LostOrderClaimId $id): ?LostOrderClaim
    {
        return $this->lostOrders->get(is_int($id) ? new LostOrderClaimId($id) : $id);
    }

    /**
     * @throws DuplicateLostOrderClaimException
     * @throws LostOrderValidationException
     * @throws LostOrderClaimUnconfirmedException
     * @throws GdeSlonException
     */
    public function submitLostOrderClaim(#[\SensitiveParameter] NewLostOrderClaim $claim, bool $checkDuplicates = true): LostOrderClaim
    {
        $claim->assertOrderDateWithin($this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow')));
        if ($checkDuplicates) {
            $existing = $this->lostOrders->find(new LostOrderCriteria(merchant: $claim->merchant()));
            if ($existing->skipped() !== []) {
                throw new UnexpectedResponseException(sprintf(
                    'Не удалось проверить дубли заявки: %d заявок магазина не разобраны (%s); проверьте вручную и передайте checkDuplicates: false',
                    count($existing->skipped()),
                    $existing->skipped()[0],
                ));
            }
            $duplicate = $existing->findByOrderNumber($claim->merchant(), $claim->orderNumber());
            if ($duplicate !== null) {
                throw new DuplicateLostOrderClaimException($duplicate);
            }
        }

        return $this->lostOrders->submit($claim);
    }

    /**
     * @throws CouponCriteriaRejectedException
     * @throws GdeSlonException
     */
    public function coupons(CouponCriteria $criteria = new CouponCriteria()): CouponList
    {
        return $this->coupons->find($criteria);
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        $properties = ['merchants', 'products', 'orders', 'lostOrders', 'coupons', 'categories', 'clock', 'feedDownloader'];
        foreach ($properties as $property) {
            try {
                serialize($this->{$property});
            } catch (InvalidArgumentException $e) {
                // отказ адаптера с секретом — уже без аргументов в трассе
                throw $e;
            } catch (\Throwable) {
                // исходное исключение не цепляем: в его трассе — объект с секретами
                throw InvalidArgumentException::withoutTraceArguments(sprintf('GdeSlon не сериализуется: не сериализуется %s (свой транспорт или кэш?)', $property));
            }
        }

        return $properties;
    }
}
