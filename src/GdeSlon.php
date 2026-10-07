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

/**
 * Точка входа в API вебмастера «Где Слон?».
 *
 * ```php
 * $gdeslon = GdeSlon::create(cache: new FileCacheStore('/var/cache/gdeslon'));
 * $tree = $gdeslon->categories();
 * $tree->get(1114)->name(); // 'Женская одежда'
 * ```
 */
final class GdeSlon
{
    /** Версия пакета (User-Agent запросов). Обновляется при релизе. */
    public const VERSION = '0.1.0-dev';

    private readonly CategoryRepository $categories;

    private readonly MerchantRepository $merchants;

    private readonly ProductCatalog $products;

    private readonly OrderRepository $orders;

    private readonly LostOrderClaims $lostOrders;

    private readonly Clock $clock;

    private readonly CouponFeed $coupons;

    /**
     * @param HttpTransport           $transport  транспорт запросов к API
     * @param CategoryRepository|null $categories свой источник категорий (например, со своим кэшем);
     *                                            по умолчанию — API «Где Слон?» без кэша
     * @param MerchantRepository|null $merchants  свой источник магазинов; по умолчанию — публичный каталог без кэша
     * @param ProductCatalog|null     $products   свой поиск товаров; по умолчанию — без токена (поиск недоступен)
     * @param OrderRepository|null    $orders     свой источник заказов; по умолчанию — без ключей (заказы недоступны)
     * @param LostOrderClaims|null    $lostOrders свои заявки на потерянные заказы; по умолчанию — без токена (недоступны)
     * @param Clock|null              $clock      часы (окно подачи заявки); по умолчанию — системные
     * @param CouponFeed|null         $coupons    свои купоны; по умолчанию — без токена (недоступны)
     */
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

    /**
     * @param Config|null        $config    настройки: токен XML API, ключи API по продажам, таймауты, User-Agent,
     *                                      загрузка частями, кэш
     * @param HttpTransport|null $transport свой транспорт — используется как есть (таймауты, User-Agent и загрузка
     *                                      частями из $config к нему не применяются; оберните его в RangeTransport сами)
     * @param CacheStore|null    $cache     где кэшировать категории и магазины (FileCacheStore, Psr16CacheStore);
     *                                      null — без кэша. Кэш магазинов содержит партнёрские ссылки вебмастера
     * @param Clock|null         $clock     часы: свежесть кэша категорий и магазинов, «сегодня» в заказах по умолчанию и
     *                                      окно подачи заявки на потерянный заказ; null — системные (для тестов —
     *                                      замороженные часы, в рабочем коде их не подменяйте)
     */
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
     * Все категории товаров (публичный справочник, ключ API не нужен).
     * Без кэша каждый вызов — новый запрос; с кэшем — см. GdeSlon::create().
     *
     * @throws GdeSlonException
     */
    public function categories(): CategoryTree
    {
        return $this->categories->all();
    }

    /**
     * Поиск товаров (офферов с партнёрскими ссылками). Нужен токен XML API в Config. Строка — ключевые слова как есть
     * («iphone -pink», «ZARA OR MANGO»); для фильтров и страниц — SearchCriteria, следующая страница — nextPage().
     *
     * @throws GdeSlonException
     */
    public function search(SearchCriteria|string $criteria = new SearchCriteria()): SearchResult
    {
        return $this->products->search(is_string($criteria) ? new SearchCriteria(query: $criteria) : $criteria);
    }

    /**
     * Магазины (рекламодатели). С токеном XML API в Config — магазины вебмастера с партнёрскими ссылками; без
     * токена — публичный каталог без ссылок. Неверный токен — AuthenticationException.
     *
     * @throws GdeSlonException
     */
    public function merchants(): MerchantList
    {
        return $this->merchants->all();
    }

    /**
     * Заказы (продажи) вебмастера. Нужны ключи API по продажам в Config (userId и apiKey,
     * https://gdeslon.ru/api_settings/orders). По умолчанию — созданные за последние 30 дней по московскому «сегодня».
     *
     * Запрос идёт 4–6 с; весь период приходит одним ответом. Статус заказа обновляется в API на следующий день. Один
     * магазин на запрос. Битые записи ответа — в OrderList::skipped().
     *
     * @throws GdeSlonException
     */
    public function orders(OrderCriteria $criteria = new OrderCriteria()): OrderList
    {
        return $this->orders->find($criteria);
    }

    /**
     * Заявки на потерянные заказы. Нужен токен XML API в Config. Магазин и статусы дополнительно проверяются на клиенте.
     *
     * @throws GdeSlonException
     */
    public function lostOrders(LostOrderCriteria $criteria = new LostOrderCriteria()): LostOrderClaimList
    {
        return $this->lostOrders->find($criteria);
    }

    /**
     * Заявка по ID; null — нет такой (или чужая). Нужен токен XML API в Config.
     *
     * @throws GdeSlonException
     */
    public function lostOrder(int|LostOrderClaimId $id): ?LostOrderClaim
    {
        return $this->lostOrders->get(is_int($id) ? new LostOrderClaimId($id) : $id);
    }

    /**
     * Создаёт РЕАЛЬНУЮ заявку на потерянный заказ у рекламодателя (POST, multipart с чеком). Нужен токен XML API.
     *
     * Ограничения проверяются до запроса: дата заказа — за последние 3 месяца, сумма с двумя знаками, чек JPEG/PNG/PDF
     * не больше 10 МиБ. С $checkDuplicates (по умолчанию) сначала ищется заявка на тот же номер заказа этого магазина —
     * DuplicateLostOrderClaimException, новая не отправляется. Проверка не защищает от параллельных вызовов (два воркера
     * очереди) — сериализуйте отправку. Запрос не повторяется: при LostOrderClaimUnconfirmedException заявка могла быть
     * создана — не повторяйте, а спустя время проверьте lostOrders(). Для больших чеков увеличьте Config::timeout
     * (например, 120).
     *
     * @throws DuplicateLostOrderClaimException   заявка на этот заказ уже есть
     * @throws LostOrderValidationException       API отклонило заявку (не создана), подробности — errors()
     * @throws LostOrderClaimUnconfirmedException исход неизвестен или ответ не разобран — НЕ повторяйте
     * @throws GdeSlonException
     */
    public function submitLostOrderClaim(NewLostOrderClaim $claim, bool $checkDuplicates = true): LostOrderClaim
    {
        // окно подачи — до любых запросов (и до проверки дублей)
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
     * Действующие купоны и промокоды магазинов вебмастера с маркировкой рекламы. Нужен токен XML API в Config. Фильтр —
     * магазины и виды (CouponList::kinds()); магазин, не подключённый вебмастеру, API отвергает. Ответ — вся выгрузка
     * (около 1 МБ, 3–4 с), без кэша.
     *
     * ⚠️ API строит партнёрские ссылки купонов с токеном XML API в пути (`/ck/<токен>/<id>`): опубликованная ссылка
     * раскрывает токен. Публикуя купон как рекламу, показывайте рядом Coupon::adMarking().
     *
     * @throws CouponCriteriaRejectedException фильтр отклонён API (errors())
     * @throws GdeSlonException
     */
    public function coupons(CouponCriteria $criteria = new CouponCriteria()): CouponList
    {
        return $this->coupons->find($criteria);
    }
}
