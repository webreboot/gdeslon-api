<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon;

use Webreboot\GdeSlon\Domain\Catalog\CategoryRepository;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\MerchantRepository;
use Webreboot\GdeSlon\Domain\Catalog\ProductCatalog;
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
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpCategoryRepository;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpMerchantRepository;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpProductCatalog;
use Webreboot\GdeSlon\Infrastructure\Api\Claims\HttpLostOrderClaims;
use Webreboot\GdeSlon\Infrastructure\Api\Promo\HttpCouponFeed;
use Webreboot\GdeSlon\Infrastructure\Api\Sales\HttpOrderRepository;
use Webreboot\GdeSlon\Infrastructure\Cache\CacheStore;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Infrastructure\Clock\SystemClock;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;

final class GdeSlon
{
    public const VERSION = '0.1.0';

    private readonly CategoryRepository $categories;

    private readonly MerchantRepository $merchants;

    private readonly ProductCatalog $products;

    private readonly OrderRepository $orders;

    private readonly LostOrderClaims $lostOrders;

    private readonly Clock $clock;

    private readonly CouponFeed $coupons;

    public function __construct(
        HttpTransport $transport,
        ?CategoryRepository $categories = null,
        ?MerchantRepository $merchants = null,
        ?ProductCatalog $products = null,
        ?OrderRepository $orders = null,
        ?LostOrderClaims $lostOrders = null,
        ?Clock $clock = null,
        ?CouponFeed $coupons = null,
    )
    {
        $client = new ApiClient($transport);
        $this->categories = $categories ?? new HttpCategoryRepository($client);
        $this->merchants = $merchants ?? new HttpMerchantRepository($client);
        $this->products = $products ?? new HttpProductCatalog($client);
        $this->orders = $orders ?? new HttpOrderRepository($client);
        $this->lostOrders = $lostOrders ?? new HttpLostOrderClaims($client);
        $this->clock = $clock ?? new SystemClock();
        $this->coupons = $coupons ?? new HttpCouponFeed($client);
    }

    public static function create(?Config $config = null, ?HttpTransport $transport = null, ?CacheStore $cache = null, ?Clock $clock = null): self
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
            new HttpProductCatalog($client, $config->apiToken()),
            new HttpOrderRepository($client, $config->userId(), $config->apiKey(), $clock),
            new HttpLostOrderClaims($client, $config->apiToken(), $clock),
            $clock,
            new HttpCouponFeed($client, $config->apiToken()),
        );
    }

    /**
     * @throws GdeSlonException
     */
    public function categories(): CategoryTree
    {
        return $this->categories->all();
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
    public function merchants(): MerchantList
    {
        return $this->merchants->all();
    }

    /**
     * @throws GdeSlonException
     */
    public function orders(OrderCriteria $criteria = new OrderCriteria()): OrderList
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
    public function submitLostOrderClaim(NewLostOrderClaim $claim, bool $checkDuplicates = true): LostOrderClaim
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
}
