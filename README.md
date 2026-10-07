# gdeslon-api

PHP SDK, CLI и MCP-сервер для API вебмастеров партнёрской сети [«Где Слон?»](https://gdeslon.ru/for_affiliates/tools/).

> **Неофициальная библиотека.** Проект не связан с «Где Слон?»; название используется только для указания
> совместимого API. Контракт API может измениться без предупреждения. Вопросы по API и аккаунту — в поддержку
> «Где Слон?», по библиотеке — в [issues](https://github.com/webreboot/gdeslon-api/issues).

- PHP 8.1+; обязательны только расширения `curl`, `json`, `libxml`, `simplexml`, `xmlreader`.
- Ответы API маппятся в неизменяемые доменные объекты; деньги — десятичные строки (`Money`), без `float`.
- Ошибки — иерархия исключений `GdeSlonException`; токены и ключи не попадают в сообщения, `var_dump` и ключи кэша.
- TLS-проверка не отключается, редиректы не выполняются, XML разбирается без внешних сущностей.
- Версия 0.1.0. До 1.0 публичный API может меняться в минорных версиях — см. [CHANGELOG.md](CHANGELOG.md).

## Покрытие API

| Ресурс | Метод SDK | Запрос | Авторизация |
|--------|-----------|--------|-------------|
| Категории товаров | `categories()` | `GET https://api.gdeslon.ru/gdeslon-categories.json` | нет |
| Магазины | `merchants()` | `GET https://www.gdeslon.ru/api/users/shops.xml` | `api_token` (опционально) |
| Поиск товаров | `search()` | `GET https://api.gdeslon.ru/api/search.xml` | `_gs_at` |
| Заказы | `orders()` | `POST https://gdeslon.ru/api/orders/` | HTTP Basic `userId:apiKey` |
| Потерянные заказы | `lostOrders()`, `lostOrder()`, `submitLostOrderClaim()` | `GET`/`POST https://gdeslon.ru/api/v1/lost-orders/` | `Bearer` |
| Купоны | `coupons()` | `GET https://gdeslon.ru/api/coupons.xml` | `api_token` |
| Postback | `PostbackReceiver` | входящий запрос от «Где Слон?» | секрет в заголовке |

Ключи: токен XML API — https://gdeslon.ru/api_settings/xml (`api_token`, `_gs_at`, `Bearer`); ID пользователя и
ключ API по продажам — https://gdeslon.ru/api_settings/orders. Это разные учётные данные.

## Установка

```bash
composer require webreboot/gdeslon-api
```

Опциональные пакеты: `psr/simple-cache` — `Psr16CacheStore`; `psr/http-message` — `PostbackRequest::fromPsr7()`.

## Быстрый старт

```php
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;

$gdeslon = GdeSlon::create(
    new Config(
        apiToken: getenv('GDESLON_API_TOKEN') ?: null,
        userId: getenv('GDESLON_USER_ID') ?: null,
        apiKey: getenv('GDESLON_API_KEY') ?: null,
    ),
    cache: new FileCacheStore('/var/cache/gdeslon'),
);

$tree = $gdeslon->categories();
$shop = $gdeslon->merchants()->get(105263);
$page = $gdeslon->search('iphone -pink');
$orders = $gdeslon->orders();
```

`GdeSlon::create(?Config $config, ?HttpTransport $transport, ?CacheStore $cache, ?Clock $clock)` собирает
cURL-транспорт с загрузкой частями и адаптеры всех ресурсов. Конструктор `new GdeSlon($transport, ...)` принимает
собственные реализации портов (`CategoryRepository`, `MerchantRepository`, `ProductCatalog`, `OrderRepository`,
`LostOrderClaims`, `Clock`, `CouponFeed`).

## Конфигурация

| Параметр `Config` | По умолчанию | Назначение |
|-------------------|--------------|------------|
| `timeout` | `30.0` | общий таймаут запроса, с |
| `connectTimeout` | `10.0` | таймаут установки соединения, с |
| `userAgent` | `null` | User-Agent; по умолчанию `webreboot-gdeslon-api/<версия> (+URL) PHP/<версия>` |
| `rangeChunkSize` | `16384` | размер части HTTP Range для `api.gdeslon.ru`, байт (≥ 1024); `null` — отключить |
| `cacheTtl` | `86400` | свежесть кэша категорий, с; `0` — ревалидация на каждый вызов |
| `merchantCacheTtl` | `3600` | свежесть кэша магазинов, с |
| `apiToken` | `null` | токен XML API: магазины вебмастера, поиск, заявки, купоны |
| `userId`, `apiKey` | `null` | ключи API по продажам; задаются парой |

Невалидные значения — `InvalidArgumentException` в конструкторе. `Config` и клиент с ключами не сериализуются,
в `__debugInfo()` ключи замаскированы.

## Ресурсы

### Категории

Публичный справочник: один JSON-документ (~156 КБ), ключ не требуется.

```php
$tree = $gdeslon->categories();

$tree->get(1114)->name();         // 'Женская одежда'; несуществующий ID — CategoryNotFoundException
$tree->get(1114)->offerCount();   // ?int, API отдаёт счётчик не для всех категорий
$tree->find(277);                 // ?Category
$tree->roots();
$tree->childrenOf(1113);
$tree->breadcrumbs(1231);         // list<Category> от корня
$tree->orphans();                 // категории с отсутствующим в выгрузке родителем
```

У «осиротевших» категорий `breadcrumbs()` пропускает недостающие звенья; полный путь из ID — `Category::path()`.

### Магазины

`shops.xml` (~1,4 МБ). С `apiToken` — магазины вебмастера с партнёрскими ссылками, тарифами и условиями; без
токена — публичный каталог без ссылок.

```php
$merchants = $gdeslon->merchants();

$shop = $merchants->get(105263);        // MerchantNotFoundException, если ID нет
$shop->affiliateLink();                 // ?string
$shop->commissionSummary();             // ?string, '10,3%'
$shop->tariffs();                       // list<Tariff>: rate() — десятичная строка, isPercent()
$shop->isTrafficTypeAllowed('Cashback'); // ?bool, null — тип не указан

$merchants->findByDomain('https://www.komus.ru/catalog');
$merchants->search('ali');              // подстрока названия или домена, без учёта регистра
$merchants->inCategory(50);             // категория магазинов, не товарная
$merchants->skipped();                  // list<string>: причины пропуска битых записей
```

- На неверный токен API отвечает 200 и публичным каталогом. Если токен задан, а в ответе нет ни одной партнёрской
  ссылки, бросается `AuthenticationException` со `statusCode() === 200`.
- Битая запись пропускается и попадает в `skipped()`; такой ответ не кэшируется. Ни одной разобранной записи или
  невалидный документ — `UnexpectedResponseException` либо копия из кэша.

### Поиск товаров

`search.xml`, требует `apiToken`. Возвращает `SearchResult` — страницу офферов.

```php
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;

$criteria = new SearchCriteria(
    query: 'платье',              // синтаксис API: AND, OR, скобки, минус-слова
    merchants: [107054, 111211],  // int|MerchantId
    excludedCategories: [26],     // без дочерних категорий
    sort: OfferSort::Price,
    limit: 20,                    // 1..100, по умолчанию 10
);

$page = $gdeslon->search($criteria);
while (true) {
    foreach ($page as $offer) {
        $offer->price();          // Money
        $offer->charge();         // ?Money, вознаграждение; '0' — API не рассчитало
        $offer->affiliateLink();
        $offer->merchantId();
    }
    $next = $page->nextPage();    // ?SearchCriteria; глубина ограничена API: page × limit ≤ 10 000
    if ($next === null) {
        break;
    }
    $page = $gdeslon->search($next);
}
```

- `total()` — `?int`; без фильтров API возвращает «≥ 1 000 000», тогда `null`.
- Невалидные критерии и вызов без токена — `InvalidArgumentException` до запроса; неверный токен —
  `AuthenticationException`.
- `search.xml` не поддерживает Range. Из сетей, где соединение с `api.gdeslon.ru` обрывается после ~16 КБ (см.
  [Загрузка частями](#загрузка-частями)), ответ с длинными описаниями не загрузится даже при `limit: 10` —
  `TimeoutException`. Обход — `limit` ≤ 5 и меньший `timeout`; SDK `limit` не меняет.

### Заказы

API по продажам, требует `userId` и `apiKey`.

```php
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;

$orders = $gdeslon->orders();      // created_at за 30 дней, «сегодня» по Europe/Moscow

$orders = $gdeslon->orders(new OrderCriteria(
    dateField: OrderDateField::LastUpdated,  // Transition, Created, LastUpdated, Confirmed, Accrued
    until: '2026-10-07',                     // включительно; по умолчанию — сегодня
    days: 7,                                 // 1..3660
    states: [OrderState::Confirmed, OrderState::Paid],
    merchant: 2573,                          // API принимает один магазин на запрос
    subId: 'blog',
));

foreach ($orders as $order) {
    $order->id();           // OrderId
    $order->state();        // OrderState: New, Cancelled, Pending, Confirmed, Paid
    $order->reward();       // Money
    $order->amount();       // ?Money, у лида может отсутствовать
    $order->lastUpdatedAt();
}
$orders->find('81234567');
$orders->skipped();
```

- Время ответа 4–6 с, пагинации нет: период приходит одним ответом. Для синхронизации статусов — короткие
  периоды по `LastUpdated`; смена статуса появляется в API на следующий день.
- На невалидный фильтр API отвечает 500 без тела, поэтому `OrderCriteria` валидирует всё до запроса.
- Фильтры передаются JSON-телом `POST`: собственный транспорт обязан отправлять `HttpRequest::body()` без
  изменений, иначе API игнорирует фильтры и отвечает пустым списком.
- Код валюты нормализуется к верхнему регистру (`rub` → `RUB`); магазины и поиск отдают `RUR`, `Money::equals()`
  эти коды не сопоставляет.
- Форма непустого ответа подтверждена только документацией. Непустой `skipped()` или `UnexpectedResponseException` —
  повод для issue (без персональных данных).

### Postback

«Где Слон?» вызывает URL вебмастера при смене статуса заказа (настройка — https://gdeslon.ru/postbacks/).
`PostbackReceiver` проверяет секрет из заголовка, разбирает query, form, JSON или XML и возвращает `Conversion`.

Подписи у postback нет: секрет в заголовке — единственная аутентификация, URL — только HTTPS. Рекомендуемый
заголовок — `X-Gdeslon-Secret`: Apache/CGI без настройки не передают в PHP `Authorization`, nginx отбрасывает
заголовки с `_`.

```php
use Webreboot\GdeSlon\Interface\Postback\HeaderSecret;
use Webreboot\GdeSlon\Interface\Postback\PostbackException;
use Webreboot\GdeSlon\Interface\Postback\PostbackFields;
use Webreboot\GdeSlon\Interface\Postback\PostbackReceiver;
use Webreboot\GdeSlon\Interface\Postback\PostbackRequest;

$receiver = new PostbackReceiver(
    new HeaderSecret('X-Gdeslon-Secret', (string) getenv('GDESLON_POSTBACK_SECRET')),  // ≥ 16 символов
    new PostbackFields(['order_id' => 'someOrderId', 'profit' => 'myProfit']),          // переименованные параметры
);

try {
    $postback = $receiver->receive(PostbackRequest::fromGlobals());
} catch (PostbackException $e) {
    http_response_code($e->responseStatus());   // 400, 401, 405, 413, 415
    header('Content-Type: text/plain; charset=utf-8');
    exit($e->getMessage());                     // без секрета и пользовательских данных
}

$conversion = $postback->conversion();
$conversion->merchantId();
$conversion->state();             // OrderState
$conversion->orderId();           // ?OrderId
$conversion->subId(2);            // sub_id2
$conversion->reward();            // ?string, '123.45'; валюта не документирована
$conversion->deduplicationKey();  // 'gdeslon:900001:3' — для UNIQUE-индекса
$postback->warnings();            // list<string>: необязательные поля, приведённые к null
```

Без суперглобалов (Laravel, Symfony):

```php
$postback = $receiver->receive(new PostbackRequest(
    method: $request->getMethod(),
    headers: $request->headers->all(),
    query: (string) $request->server->get('QUERY_STRING', ''),
    body: $request->getContent(),
    form: $request->request->all(),
));
// PSR-7: PostbackRequest::fromPsr7($psrRequest)
```

- Обязательны `merchant_id` и `state`. Невалидное необязательное поле становится `null` с записью в `warnings()`,
  конверсия не отбрасывается.
- Все значения — недоверенный ввод: экранирование при выводе, подготовленные запросы к БД.
- XML — только UTF-8 без DOCTYPE. Для multipart нужна разобранная форма (`form`). PHP заменяет `.` и пробел в именах
  параметров `$_POST` на `_` — имена параметров ограничьте `[A-Za-z0-9_]`.
- Формат времени и сумм в реальном postback не подтверждён (`docs/gdeslon-api/postback.md`). Принимаются ISO 8601,
  `Y-m-d H:i:s` (без пояса — Europe/Moscow) и unix-время; суммы — `123.45`.
- Postback — сигнал о событии. Суммы для учёта сверяются через `orders()`.
- В Laravel маршрут исключается из CSRF-проверки.

### Потерянные заказы

Заявка рекламодателю на заказ, не попавший в статистику (FAQ 74). Требует `apiToken`.

```php
use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Infrastructure\Claims\AttachmentFile;

$claims = $gdeslon->lostOrders(new LostOrderCriteria(merchant: 2573, orderStatus: LostOrderStatus::Waiting));
$gdeslon->lostOrder(5796);   // ?LostOrderClaim

try {
    $created = $gdeslon->submitLostOrderClaim(new NewLostOrderClaim(
        orderNumber: 'GS123L',
        orderDate: '2026-09-24',                                // не старше 3 месяцев
        orderTotal: '554.34',                                   // до 2 знаков, без валюты
        merchant: 2573,
        attachment: AttachmentFile::load('/path/receipt.pdf'),  // JPEG, PNG, PDF ≤ 10 МиБ
        description: 'Заказ после перехода с сайта',
    ));
} catch (DuplicateLostOrderClaimException $e) {
    $e->existing();      // заявка на этот номер уже есть, запрос создания не отправлялся
} catch (LostOrderValidationException $e) {
    $e->errors();        // array<string, list<string>>, заявка не создана
} catch (LostOrderClaimUnconfirmedException $e) {
    $e->wasCreated();    // исход неизвестен (таймаут, обрыв, 5xx)
}
```

`submitLostOrderClaim()` создаёт **реальную** заявку у рекламодателя.

- Перед `POST` выполняется поиск заявки с тем же номером заказа и магазином (`checkDuplicates: false` — отключить).
  Проверка не атомарна: при параллельной отправке (несколько воркеров) сериализуйте вызовы.
- `POST` никогда не повторяется автоматически. После `LostOrderClaimUnconfirmedException` повтор запрещён:
  результат проверяется через `lostOrders()`.
- API принимает документированные фильтры статусов с любым значением, поэтому статусы дополнительно фильтруются на
  клиенте.
- Магазин в фильтре должен существовать в справочнике «Где Слон?», иначе 400 → `LostOrderValidationException`.
- Форма ответа со списком подтверждена только документацией; непустой `skipped()` — повод для issue.

Рекомендуемый `timeout` для отправки — 120 с.

### Купоны

XML-выгрузка действующих купонов магазинов вебмастера (~1 МБ, 3–4 с, без пагинации). Требует `apiToken`.

```php
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;

$coupons = $gdeslon->coupons(new CouponCriteria(merchants: [99157], kinds: [1]));

foreach ($coupons->activeAt(new DateTimeImmutable()) as $coupon) {
    $coupon->code();                     // ?string, null — акция без промокода
    $coupon->kind()->name();
    $coupon->endsAt();                   // DateTimeImmutable, Europe/Moscow
    $coupon->affiliateLinkWithCode() ?? $coupon->affiliateLink();
    $coupon->adMarking();                // маркировка рекламы (erid)
}
$coupons->kinds();
$coupons->skipped();
```

- **API встраивает токен XML API в партнёрскую ссылку купона** (`http://xf.gdeslon.ru/ck/<токен>/<id>`). Публикация
  ссылки раскрывает токен, которым читаются магазины и создаются заявки. SDK ссылки не переписывает (сломается
  атрибуция) и купоны не кэширует. `var_dump` маскирует токен, но `serialize()`, `var_export()` и JSON содержат его
  в открытом виде.
- `adMarking()` есть только в XML-выгрузке; при публикации купона как рекламы маркировка обязательна.
- Магазин, не подключённый вебмастеру, в фильтре — `CouponCriteriaRejectedException` (`errors()`).

## Кэширование

Категории и магазины кэшируются через `CacheStore`. Внутри TTL документ отдаётся без запроса; после TTL —
условный запрос (`If-None-Match`/`If-Modified-Since`), на 304 копия продлевается. При сетевой ошибке, 5xx или
невалидном ответе возвращается сохранённая копия.

```php
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;
use Webreboot\GdeSlon\Infrastructure\Cache\Psr16CacheStore;

GdeSlon::create(cache: new FileCacheStore('/var/cache/gdeslon'));
GdeSlon::create(cache: new Psr16CacheStore($psr16Cache));   // psr/simple-cache
```

- Кэш магазинов содержит партнёрские ссылки вебмастера: каталог должен быть доступен только процессу приложения
  (не общий `/tmp`). Токен в кэш не записывается, кэши разных токенов разделены.
- Документ магазинов ~1,5 МБ: хранилища с лимитом записи 1 МБ (Memcached по умолчанию) его не сохранят, SDK
  продолжит работу без кэша.

## HTTP

### Загрузка частями

Из части российских сетей TCP-соединение с `api.gdeslon.ru` (Hetzner) зависает после ~16 КБ тела. Поэтому ответы
этого хоста по умолчанию загружаются частями по 16 КиБ через HTTP Range, каждая часть — отдельным соединением
(`Connection: close`). Целостность проверяется по общему размеру из `Content-Range` и ETag/Last-Modified каждой
части; зависшая часть повторяется с меньшим размером. Сервер без поддержки Range отвечает целиком (`200`), первая
часть не повторяется.

```php
new Config(rangeChunkSize: 8192);   // меньше часть
new Config(rangeChunkSize: null);   // один запрос
```

### Собственный транспорт

Точка расширения — `Webreboot\GdeSlon\Infrastructure\Http\HttpTransport::send(HttpRequest): HttpResponse` (прокси,
логирование, адаптер к PSR-18-клиенту). Передаётся в `GdeSlon::create(transport: $transport)` и используется как
есть: `timeout`, `userAgent` и `rangeChunkSize` из `Config` к нему не применяются.

Требования к реализации:
- метод и тело (`HttpRequest::body()`) отправляются байт-в-байт;
- редиректы не выполняются, TLS проверяется;
- для обёртки `new RangeTransport($transport)` — соблюдение `HttpRequest::timeout()` и отказ от переиспользования
  соединения при `Connection: close`.

## Исключения

Все исключения реализуют `Webreboot\GdeSlon\Exception\GdeSlonException`. Сообщение содержит метод, URL без
секретов и причину.

| Исключение | Условие |
|------------|---------|
| `TransportException` | DNS, отказ соединения, TLS, обрыв ответа (`curlErrorCode()`) |
| `TimeoutException` | истёк таймаут; наследует `TransportException` |
| `HttpException` | статус вне 2xx (`statusCode()`, `responseSnippet()`) |
| `AuthenticationException` | 401/403 или учётные данные не приняты; наследует `HttpException` |
| `UnexpectedResponseException` | невалидный JSON/XML, неожиданная структура документа |
| `InvalidArgumentException` | невалидные настройки, критерии или значения (до запроса) |
| `CategoryNotFoundException`, `MerchantNotFoundException` | `get()` с несуществующим ID |
| `Domain\Claims\*Exception`, `CouponCriteriaRejectedException` | исходы заявок и отказ в фильтре купонов |
| `Interface\Postback\PostbackException` | невалидный или неаутентифицированный postback (`responseStatus()`) |

## CLI

`vendor/bin/gdeslon` — команды поверх SDK с табличным выводом и стабильным JSON (`--format=json`). Ключи читаются
только из окружения или `--env-file`, токен в выводе маскируется.

```bash
vendor/bin/gdeslon categories 1 --depth=1
vendor/bin/gdeslon merchants --search=komus
vendor/bin/gdeslon search платье красное --limit=20 --sort=price
vendor/bin/gdeslon orders --days=7 --state=confirmed,paid
vendor/bin/gdeslon coupons --active --format=json
vendor/bin/gdeslon lost-orders submit --merchant=2573 --order-number=GS123L --order-date=2026-09-24 \
    --order-total=554.34 --attachment=receipt.pdf --dry-run
```

Коды выхода: 0 — успех, 1 — ошибка выполнения, 2 — неверный вызов, 3 — нет ключей, 4 — исход заявки неизвестен
(не повторять), 5 — заявка уже существует. Команды, опции и JSON-схема — [docs/cli.md](docs/cli.md).

## MCP-сервер

`gdeslon mcp` — stdio MCP-сервер с инструментами только для чтения: `get_categories`, `list_merchants`,
`search_offers`, `list_orders`, `list_coupons` и др. Протокол: `2024-11-05`…`2025-11-25` (рукопожатие
`initialize`) и `2026-07-28`.

```bash
claude mcp add --transport stdio gdeslon -- php /path/to/project/vendor/bin/gdeslon mcp --env-file=/home/me/.config/gdeslon.env
```

Ключи маскируются в ответах; токен в ссылках купонов заменяется на `/ck/***/`, исходные ссылки — только с
`--reveal-links`. Инструменты, аргументы, теги ошибок и безопасность — [docs/mcp.md](docs/mcp.md).

## Архитектура

```
src/
  GdeSlon.php, Config.php   фасад и настройки
  Exception/                GdeSlonException и общие исключения
  Domain/                   модель без I/O: Catalog, Sales, Claims, Promo, Shared; порты репозиториев и часов
  Infrastructure/           адаптеры портов: Http (cURL, Range), Api (запросы и мапперы), Cache, Clock
  Interface/                Cli, Mcp (JSON-RPC по stdio), Postback, Normalizer (JSON-представление для CLI и MCP)
```

## Совместимость

Публичный API — фасад `GdeSlon`, `Config`, доменные объекты, порты, `CacheStore`/`HttpTransport` и их реализации,
`Interface\Postback`. Классы и методы с `@internal` в него не входят.

- Новые параметры конструкторов добавляются только в конец списка; вызывайте конструкторы с именованными
  аргументами.
- До 1.0 несовместимые изменения возможны в минорных версиях и фиксируются в [CHANGELOG.md](CHANGELOG.md).
- Стабильность CLI и MCP описана в [docs/cli.md](docs/cli.md#совместимость) и [docs/mcp.md](docs/mcp.md#совместимость).

## Разработка

```bash
composer install
composer check   # phpstan level max + PHPUnit
```

Тесты не обращаются к сети: адаптеры проверяются на фейковом транспорте и обезличенных фикстурах реальных ответов.
Справочник API с проверенными фактами — [docs/gdeslon-api/](docs/gdeslon-api/README.md).

## Лицензия

MIT, © webreboot. «Где Слон?» — название и товарный знак их владельца.
