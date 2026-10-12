# gdeslon-api

PHP SDK, CLI и MCP-сервер для API вебмастеров партнёрской сети [«Где Слон?»](https://gdeslon.ru/for_affiliates/tools/).

> **Неофициальная библиотека.** Проект не связан с «Где Слон?»; название используется только для указания
> совместимого API. Контракт API может измениться без предупреждения. Вопросы по API и аккаунту — в поддержку
> «Где Слон?», по библиотеке — в [issues](https://github.com/webreboot/gdeslon-api/issues).

- PHP 8.1+; обязательны только расширения `curl`, `json`, `libxml`, `simplexml`, `xmlreader`.
- Ответы API маппятся в неизменяемые доменные объекты; деньги — десятичные строки (`Money`), без `float`.
- Ошибки — иерархия исключений `GdeSlonException`; токены и ключи не попадают в сообщения, `var_dump` и ключи кэша.
- TLS-проверка не отключается, редиректы не выполняются, XML разбирается без внешних сущностей.
- Версия 0.3.0. До 1.0 публичный API может меняться в минорных версиях — см. [CHANGELOG.md](CHANGELOG.md).

## Покрытие API

| Ресурс | Метод SDK | Запрос | Авторизация |
|--------|-----------|--------|-------------|
| Категории товаров | `categories()` | `GET https://api.gdeslon.ru/gdeslon-categories.json` | нет |
| Магазины | `merchants()`, `merchants(public: true)` | `GET https://www.gdeslon.ru/api/users/shops.xml` | `api_token`; публичный каталог — нет |
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
$shop = $gdeslon->merchants()->get(105263);             // магазины вебмастера; без apiToken — InvalidArgumentException
$all = $gdeslon->merchants(public: true);                // публичный каталог без партнёрских ссылок, токен не нужен
$page = $gdeslon->search('iphone -pink');
$orders = $gdeslon->orders();
```

`GdeSlon::create(?Config $config, ?HttpTransport $transport, ?CacheStore $cache, ?Clock $clock)` собирает
cURL-транспорт с загрузкой частями и адаптеры всех ресурсов. Конструктор `new GdeSlon($transport, ...)` принимает
собственные реализации портов (`CategoryRepository`, `MerchantRepository`, `ProductCatalog`, `OrderRepository`,
`LostOrderClaims`, `Clock`, `CouponFeed`). Сигнатуры портов каталога:
`CategoryRepository::all(bool $refresh = false): CategoryTree` и
`MerchantRepository::all(bool $public = false, bool $refresh = false): MerchantList`; своя реализация, отдающая
копию, помечает её через `withOrigin()` (см. «Кэширование»).

## Конфигурация

| Параметр `Config` | По умолчанию | Назначение |
|-------------------|--------------|------------|
| `timeout` | `30.0` | общий таймаут запроса, с |
| `connectTimeout` | `10.0` | таймаут установки соединения, с; истёк — `ConnectTimeoutException` |
| `userAgent` | `null` | User-Agent; по умолчанию `webreboot-gdeslon-api/<версия> (+URL) PHP/<версия>` |
| `rangeChunkSize` | `16384` | размер части HTTP Range для `api.gdeslon.ru`, байт (≥ 1024); `null` — отключить |
| `cacheTtl` | `86400` | свежесть кэша категорий, с; `0` — ревалидация на каждый вызов |
| `merchantCacheTtl` | `3600` | свежесть кэша магазинов, с |
| `apiToken` | `null` | токен XML API: магазины вебмастера, поиск, заявки, купоны |
| `userId`, `apiKey` | `null` | ключи API по продажам; задаются парой |
| `searchFallbackLimit` | `5` | размер частей повтора поиска после таймаута, 1..100; подбирается под порог сети; `null` — без повтора (см. [Повтор меньшими страницами](#повтор-меньшими-страницами)) |
| `connectRetries` | `1` | повторы GET/HEAD после `ConnectTimeoutException`, 0..5; `0` — без повтора (см. [Собственный транспорт](#собственный-транспорт)) |

Невалидные значения — `InvalidArgumentException` в конструкторе. `Config` и клиент с ключами не сериализуются,
в `__debugInfo()` ключи замаскированы.

## Ресурсы

### Категории

Публичный справочник: один JSON-документ (~156 КБ), ключ не требуется.

```php
$tree = $gdeslon->categories();
$tree = $gdeslon->categories(refresh: true);   // в обход свежести кэша, см. «Кэширование»

$tree->get(1114)->name();         // 'Женская одежда'; несуществующий ID — CategoryNotFoundException
$tree->get(1114)->offerCount();   // ?int, API отдаёт счётчик не для всех категорий
$tree->find(277);                 // ?Category
$tree->roots();
$tree->childrenOf(1113);
$tree->breadcrumbs(1231);         // list<Category> от корня
$tree->descendantsOf(1114);       // list<Category>: всё поддерево без самой 1114
$tree->descendantsOf(1114, reachableOnly: true);   // без архивных веток и «сирот»
$tree->isReachable(1052);         // false: сирота; неизвестный ID — тоже false, без исключения
$tree->orphans();                 // категории с отсутствующим в выгрузке родителем
```

У «осиротевших» категорий `breadcrumbs()` пропускает недостающие звенья; полный путь из ID — `Category::path()`.

`descendantsOf()` возвращает категории, в чьём `path()` есть указанная, кроме неё самой, в порядке `all()`; архивные
входят. Поэтому «сирота» попадает в поддерево существующего предка из своего пути (`childrenOf()` и обход по
`parentId()` её не находят). Несуществующий ID — `CategoryNotFoundException`.

`descendantsOf($id, reachableOnly: true)` оставляет только достижимые категории: путь `path()` начинается с
существующего корня, и каждый его шаг, включая саму категорию, есть в выгрузке и не в архиве. Так отсекаются архивные
ветки, «сироты» и их потомки. Достижимость, как и само поддерево, считается по `path()`, а не по цепочке `parentId()`. Если недостижима сама `$id`, результат — `[]`; несуществующий ID — по-прежнему
`CategoryNotFoundException`. Проверить одну категорию — `isReachable()`. CLI (`search --subcategories`) и MCP
(`include_subcategories`) берут полное поддерево.

Поиск по категории (`tid`) берёт только саму категорию, без дочерних. Чтобы искать по разделу целиком, передайте
его достижимое поддерево (у сирот, по наблюдениям 2026-10-12, офферов нет):

```php
use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;

$tree = $gdeslon->categories();
$page = $gdeslon->search(new SearchCriteria(
    query: 'тренч',
    categories: [new CategoryId(1114), ...array_map(
        static fn (Category $c): CategoryId => $c->id(),
        $tree->descendantsOf(1114, reachableOnly: true),
    )],
));
```

Длина адреса запроса ограничена сервером (~8 КиБ): поддерево 1114 (65 ID) — около 0,6 КБ, самый большой раздел
1113 «Одежда» (516 ID) — около 3,7 КБ, все категории сразу не пройдут. В бюджет входят и `query`, магазины, артикулы.
Слишком длинный запрос — `HttpException` со `statusCode() === 414` и подсказкой сократить списки.

### Магазины

Два режима одного `shops.xml`:

- `merchants()` — магазины вебмастера с партнёрскими ссылками, тарифами и условиями (~1,4 МБ). Нужен `apiToken`:
  без него — `InvalidArgumentException` до запроса, в том числе при свежей копии публичного каталога в кэше.
- `merchants(public: true)` — публичный каталог всех магазинов сети без партнёрских ссылок (~2 МБ, магазинов больше,
  чем у вебмастера). Токен не отправляется, даже если задан; копия в кэше одна на всех.

```php
$merchants = $gdeslon->merchants();
$catalog = $gdeslon->merchants(public: true);   // affiliateLink() у всех null
$merchants = $gdeslon->merchants(refresh: true); // в обход свежести кэша, см. «Кэширование»

$shop = $merchants->get(105263);        // MerchantNotFoundException, если ID нет
$shop->affiliateLink();                 // ?string
$shop->link()?->withSubId('site');      // ?AffiliateLink, см. «Партнёрская ссылка и sub_id»
$shop->erid();                          // ?string, см. «Маркировка рекламы и erid»
$shop->commissionSummary();             // ?string, '10,3%'
$shop->tariffs();                       // list<Tariff>: rate() — десятичная строка, isPercent()
$shop->isTrafficTypeAllowed('Cashback'); // ?bool, null — тип не указан

$merchants->findByDomain('https://www.komus.ru/catalog');
$merchants->search('ali');              // подстрока названия или домена, без учёта регистра
$merchants->inCategory(50);             // категория магазинов, не товарная
$merchants->skipped();                  // list<string>: причины пропуска битых записей
```

- На неверный токен API отвечает 200 и публичным каталогом. Если в ответе `merchants()` нет ни одной партнёрской
  ссылки, бросается `AuthenticationException` со `statusCode() === 200`. В публичном режиме ссылок нет по
  определению, эта проверка не делается; остальные ошибки (HTTP, транспорт, битый документ) — как в обычном.
- Битая запись пропускается и попадает в `skipped()`; такой ответ не кэшируется. Ни одной разобранной записи или
  невалидный документ — `UnexpectedResponseException` либо копия из кэша.
- Тексты магазина (название, описания, условия, вид, маркировка, имена категорий и типов трафика) — plain text,
  см. [Тексты](#тексты). `logoUrl()` с адресом не http(s) — `null`, магазин не пропускается.

### Поиск товаров

`search.xml`, требует `apiToken`. Возвращает `SearchResult` — страницу офферов.

```php
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;

$criteria = new SearchCriteria(
    query: 'платье',              // синтаксис API: AND, OR, скобки, минус-слова
    merchants: [107054, 111211],  // int|MerchantId
    excludedCategories: [26],     // только сама категория, без дочерних — см. «Категории»
    sort: OfferSort::Price,
    limit: 20,                    // 1..100, по умолчанию 10
);

$page = $gdeslon->search($criteria);
while (true) {
    foreach ($page as $offer) {
        $offer->price();          // Money больше нуля, currency() === 'RUB'
        $offer->charge();         // ?Money, вознаграждение; null — API не рассчитало (в ответе 0)
        $offer->affiliateLink();
        $offer->link()->withSubId('tg_channel-42'); // AffiliateLink с меткой источника
        $offer->erid();           // ?string, идентификатор рекламы
        $offer->key();            // ?OfferKey, '107054:578237' — для склейки повторов
        $offer->merchantId();
        $offer->pictureCandidates(); // list<string>: originalPicture → picture → thumbnail
    }
    $next = $page->nextPage();    // ?SearchCriteria; глубина ограничена API: page × limit ≤ 10 000
    if ($next === null) {
        break;
    }
    $page = $gdeslon->search($next);
}
```

- `total()` — `?int`. API отвечает `documents_number = 1000000` и на «неизвестно» (без фильтров), и на «ничего не
  найдено». SDK различает их так: пустая первая страница — `0`, иначе 1 000 000 — `null`; любое другое число — как есть.
- Оффер с ценой 0 в страницу не попадает: он в `skipped()` (`оффер «…»: цена 0, оффер пропущен`), как и битые
  записи. Поэтому на странице может быть меньше `limit` офферов или ни одного при `nextPage() !== null` — листайте по
  `nextPage()`, а не до первой пустой страницы.
- Валюта `RUR` из ответа приводится к `RUB` ([`Currency::normalize()`](#валюта)); оффер с нераспознанным
  `currencyId` — в `skipped()`.
- Адрес картинки или `productUrl()` не http(s) — `null`, оффер не пропускается. Тексты (`name()`, `description()`,
  `vendor()`, `model()`, `adMarking()`) — plain text, см. [Тексты](#тексты).
- Невалидные критерии и вызов без токена — `InvalidArgumentException` до запроса; неверный токен —
  `AuthenticationException`; адрес запроса длиннее ~8 КиБ — `HttpException` 414 (см. [Категории](#категории)).
- Страница, не загрузившаяся за `timeout`, загружается повторно меньшими страницами — см.
  [Повтор меньшими страницами](#повтор-меньшими-страницами).

#### Повтор меньшими страницами

`search.xml` не поддерживает Range. Из сетей, где соединение с `api.gdeslon.ru` обрывается после ~16 КБ (см.
[Загрузка частями](#загрузка-частями)), ответ с длинными описаниями не загружается даже при `limit: 10`: запрос
заканчивается по `timeout` с `TimeoutException`. Тогда SDK загружает ту же страницу частями: `limit: 10, page: 3` —
это офферы 21–30, они же — страницы 5 и 6 по 5. Часть, которая тоже не уложилась в `timeout`, делится ещё раз: часть
5 по 5 — это 5 частей по 1. Склеенный результат описывает исходный запрос:

```php
$page = $gdeslon->search(new SearchCriteria(query: 'платье', limit: 10));
$page->isDegraded();        // true — страница собрана из нескольких запросов
$page->effectiveLimit();    // 5 — наименьший размер части; без повтора равен limit
$page->criteria()->limit(); // 10
$page->nextPage();          // page 2, limit 10 — листание не меняется

new Config(apiToken: $token, searchFallbackLimit: null);   // без повтора: сразу TimeoutException
```

- Размер частей — наибольший делитель `limit`, не больший `searchFallbackLimit`: `limit: 12` — 3 запроса по 4.
  Зависшая часть делится по наибольшему делителю её размера, не большему половины (10 → 5, 5 → 1, 4 → 2, 9 → 3), и
  загружается сразу, до следующих частей; окно и порядок офферов не меняются. `effectiveLimit()` — наименьший размер
  части, из которой пришёл ответ.
- Под-запросов не больше 20 на страницу, включая деление: `limit: 97` делится только по 1, поэтому повтора нет —
  `TimeoutException` с подсказкой. `limit: 10` по 5 выдерживает зависание обеих частей (12 под-запросов), `limit: 20`
  по 5 — трёх из четырёх, у `limit: 100` по 5 запаса на деление нет. Если на деление не хватает под-запросов —
  `TimeoutException` «… не хватает под-запросов …» сразу, остаток окна не запрашивается. Выбирайте `limit`, кратный 5.
- `searchFallbackLimit` подбирается под порог сети: части по 5 — это ~10 КБ, по 2 — ~4 КБ. Если в вашей сети
  зависают ответы около 10 КБ, задайте `searchFallbackLimit: 2` — меньше зависаний, а значит, меньше ожидания
  `timeout`.
- Если `limit` не больше `searchFallbackLimit` или повтор выключен, повтора нет: `TimeoutException` с подсказкой
  уменьшить `limit`.
- Повтор начинается после полного `timeout` первого запроса; каждая зависшая часть стоит ещё один `timeout`.
- Таймаут соединения — `ConnectTimeoutException` (DNS, TCP, TLS или туннель прокси не установлены, запрос не
  отправлен) — деление не запускает. Транспорт `GdeSlon::create($config)` сам повторяет такой запрос тем же размером
  `connectRetries` раз (по умолчанию 1); попытки кончились — исключение пробрасывается без подсказки про `limit`, и
  при недоступном API страница ждёт около `connectTimeout` × (`connectRetries` + 1). На под-запросе — так же,
  остальные под-запросы не выполняются.
- Ответ части короче её размера (офферы вместе со `skipped()`) — конец выдачи, остальные части не запрашиваются.
  `total()` — из первого загруженного ответа.
- Часть по 1 тоже не уложилась в `timeout` — `TimeoutException` «Страница limit … не загрузилась: часть l=1&p=… (оффер
  …) превысила время ожидания, делить её дальше некуда …», частичного результата нет. Другие ошибки первого запроса и
  частей пробрасываются как есть, без повтора.
- С `query` выдача стабильна, и склейка точная. Без `query` по большому набору порядок выдачи меняется от запроса к
  запросу, поэтому в склеенной странице возможны повторы и пропуски — как между соседними страницами. Убирайте
  повторы по `key()` (см. [Повторы между страницами](#повторы-между-страницами)).
- На частые запросы API отвечает `HttpException` 420 `Rate limit exceeded!` без `Retry-After` — в том числе на
  под-запросе. SDK его не повторяет: сделайте паузу.
- Повтор запускает только таймаут чтения — `TimeoutException`, но не его подкласс `ConnectTimeoutException`:
  [собственный транспорт](#собственный-транспорт) должен их различать. Свой `ProductCatalog`, переданный в конструктор
  `GdeSlon`, не оборачивается.

#### Картинки

`pictureCandidates()` — адреса картинок оффера по убыванию надёжности, без пустых и повторов; нет картинок — `[]`:

1. `originalPicture()` — картинка с сайта магазина, бывает по `http://`;
2. `picture()` — копия на `imgng.gdeslon.ru`, по наблюдениям ≈450 px;
3. `thumbnail()` — миниатюра там же, по наблюдениям ≈100 px.

Размеры не документированы. `imgng.gdeslon.ru` отвечает 502 для части товаров, поэтому не встраивайте ссылки на
картинки в страницы напрямую: скачивайте к себе с повторами и при ошибке берите следующий адрес.

```php
foreach ($offer->pictureCandidates() as $url) {
    $image = $downloader->fetch($url);   // ваш HTTP-клиент: таймаут, повторы, проверка Content-Type
    if ($image !== null) {
        break;
    }
}
```

#### Тексты

Тексты офферов, магазинов и купонов — plain text: HTML-сущности из ответа (`&quot;`, `&nbsp;`, `&laquo;`) раскодированы
один раз, дважды экранированная сущность (`&amp;mdash;` в ответе) остаётся текстом `&mdash;`. В описаниях бывает
разметка из API, например `<br/>`, — при выводе в HTML экранируйте (`htmlspecialchars()`), а переводы строк
превращайте в разметку сами. Коды купонов, адреса и атрибуты тарифов не раскодируются.

Адреса (картинки, `productUrl()`, `logoUrl()`) SDK проверяет только на схему http(s) и хост: кавычки, пробелы и `<`
в пути возможны. Партнёрские ссылки вдобавок не содержат пробелов и управляющих символов, но кавычки и `<` в них тоже
возможны. В HTML-атрибуты (`src`, `href`) выводите адреса через `htmlspecialchars()`.

#### Партнёрская ссылка и sub_id

`link()` у оффера, магазина (`?AffiliateLink`, без токена — `null`) и купона (`linkWithCode()` — ссылка с промокодом)
— партнёрская ссылка как объект. `url()` и `(string)` — байт в байт как в ответе API; `affiliateLink()` возвращает ту
же строку, что и раньше.

```php
use Webreboot\GdeSlon\Domain\Shared\SubId;

$url = (string) $offer->link()->withSubId('tg_channel-42');          // …&erid=2SDnjcZqvUG&sub_id=tg_channel-42
$url = $offer->link()->withSubId('tg')->withSubId('post-7', 2)->url(); // sub_id и sub_id2

SubId::isValid($input);         // bool — проверка метки до вызова
$offer->link()->subId();        // ?string, sub_id, уже стоящий в ссылке; пустой — null
$offer->link()->merchantId();   // ?MerchantId из параметра mid
```

- `withSubId($value, $position = 1)` возвращает новую ссылку: `sub_id` (`sub_id2`…`sub_id5` для позиций 2–5)
  дописывается в конец query, перед `#`. Если параметр уже есть (в JSON-купонах API встречается `sub_id=None`), он
  заменяется на месте первого вхождения, повторы удаляются. Остальные байты ссылки не меняются и не перекодируются.
- Значение — `[A-Za-z0-9_-]{1,64}` (строка или `SubId`), позиция — 1…5, иначе `InvalidArgumentException` без значения
  в сообщении; исходная ссылка не меняется. Это правило SDK, а не API: ограничения длины и символов `sub_id` в
  документации нет, и живьём они не подтверждены. Метку другого формата (кириллица, пробелы, длиннее 64 символов)
  закодируйте (`rawurlencode()`) и допишите к `url()` сами.
- Ссылку из ответа SDK проверяет при разборе: http(s), хост, без пробелов и управляющих символов. Запись с такой
  ссылкой (оффер, магазин, купон) попадает в `skipped()`.
- У купона `withSubId()` токен не скрывает. Для логов и показа берите `masked()` (`/ck/***/`); `var_dump` ссылки тоже
  маскирует токен.

#### Маркировка рекламы и erid

`erid()` у оффера, магазина и купона — идентификатор рекламы (erid): из маркировки `adMarking()` (значение после
слова `erid` через пробел или двоеточие, последнее вхождение), а если там его нет, из параметра `erid` партнёрской
ссылки. `null` означает, что erid нет ни там, ни там: так у AliExpress и у части
офферов, чья маркировка — «Рекламодатель …, ИНН …, сайт» без erid, хотя у их магазина erid есть. SDK не подставляет
erid магазина в оффер: можно ли публиковать такой оффер, решаете вы.

```php
$offer->erid();    // ?string, '2SDnjcZqvUG'
$shop->erid();     // магазин без партнёрской ссылки — erid из маркировки
$coupon->erid();
```

#### Повторы между страницами

Соседние страницы поиска одного магазина пересекаются: оффер с прошлой страницы может прийти снова. Склеивайте
повторы по `key()` — `?OfferKey` из ID магазина и артикула (`"107054:578237"`, `null` без артикула): уникальность
`id()` API не гарантирует, а один артикул у разных магазинов даёт разные ключи.

```php
use Webreboot\GdeSlon\Domain\Catalog\OfferKey;

$offers = [];
foreach ($page as $offer) {
    $offers[(string) ($offer->key() ?? $offer->id())] ??= $offer;   // первое вхождение побеждает
}

$saved = OfferKey::fromString('107054:578237');   // ключ из своего хранилища
$saved->merchantId();                             // MerchantId
$saved->article();                                // '578237'
$offer->key()?->equals($saved);                   // ?bool, null — у оффера нет артикула
```

Артикул в ключе — как в ответе API, с кириллицей и пробелами, поэтому ключ не годится как имя файла или часть URL без
кодирования (`rawurlencode()` или хэш).

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
- Код валюты нормализуется: `rub`, `RUR` → `RUB` (см. [Валюта](#валюта)).
- Форма непустого ответа подтверждена только документацией. Непустой `skipped()` или `UnexpectedResponseException` —
  повод для issue (без персональных данных).

#### Валюта

Коды валют в ответах API (поиск, заказы, postback) приводятся `Currency::normalize()`: пробелы по краям убираются,
регистр — верхний, устаревший `RUR` становится `RUB`. Так `Money::equals()` сопоставляет суммы из разных источников.
Нормализуйте так же коды из своих данных:

```php
use Webreboot\GdeSlon\Domain\Shared\Currency;

Currency::normalize(' rur ');   // 'RUB'
Currency::normalize('usd');     // 'USD'
Currency::normalize('РУБ');     // InvalidArgumentException: не три латинские буквы
```

`new Money()` код не нормализует и принимает только три заглавные латинские буквы.

#### Суммы

`Money` складывает и сравнивает суммы одной валюты точно, без `float` и `bcmath`, при любой длине числа:

```php
use Webreboot\GdeSlon\Domain\Shared\Money;

$total = new Money('0', 'RUB');
foreach ($orders as $order) {
    $total = $total->plus($order->reward());   // новый Money, операнды не меняются
}

(new Money('1.50', 'RUB'))->plus(new Money('2.5', 'RUB'));      // 4.00 RUB
(new Money('10', 'RUB'))->compare(new Money('9.99', 'RUB'));    // 1 (-1, 0 или 1)
$offer->oldPrice()?->isGreaterThan($offer->price());             // есть скидка
usort($offers, fn (Offer $a, Offer $b) => $a->price()->compare($b->price()));
```

- Округления нет: в сумме столько знаков дроби, сколько у более длинного слагаемого (`'100' + '0.00'` → `'100.00'`,
  `'0.0000000001' + '0.9999999999'` → `'1.0000000000'`); ведущие нули целой части убираются (`'007' + '1'` → `'8'`).
  Округляйте до копеек сами при выводе.
- Сравнивается значение, а не запись: `'100'` и `'100.00'` равны.
- `plus()`, `compare()`, `isGreaterThan()`, `isLessThan()` для разных валют бросают `InvalidArgumentException`
  (в сообщении только коды валют), а `equals()` возвращает `false`. Поэтому `equals()` и `compare() === 0` совпадают
  только для одной валюты. Нормализуйте коды из своих данных через `Currency::normalize()`, иначе `RUR` и `RUB` —
  разные валюты.

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
- `currency()` нормализуется как в заказах (`RUR` → `RUB`, см. [Валюта](#валюта)); нераспознанный код — `null` и
  запись в `warnings()`.
- Все значения — недоверенный ввод: экранирование при выводе, подготовленные запросы к БД.
- `HeaderSecret` и `PostbackReceiver` не сериализуются. `PostbackRequest` сериализуется вместе с заголовком секрета —
  перед сохранением входящего запроса заголовок секрета удаляется.
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
    ($coupon->linkWithCode() ?? $coupon->link())->withSubId('site'); // AffiliateLink с sub_id
    $coupon->adMarking();                // маркировка рекламы
    $coupon->erid();                     // ?string, erid из маркировки или ссылки
}
$coupons->kinds();
$coupons->skipped();
```

- **API встраивает токен XML API в партнёрскую ссылку купона** (`http://xf.gdeslon.ru/ck/<токен>/<id>`). Публикация
  ссылки раскрывает токен, которым читаются магазины и создаются заявки. SDK ссылки не переписывает (сломается
  атрибуция) и купоны не кэширует. `var_dump` маскирует токен, но `serialize()`, `var_export()` и JSON содержат его
  в открытом виде. Для логов и показа — `$coupon->link()->masked()` (`/ck/***/`), в том числе после `withSubId()`.
- `adMarking()` есть только в XML-выгрузке; при публикации купона как рекламы маркировка обязательна.
- Магазин, не подключённый вебмастеру, в фильтре — `CouponCriteriaRejectedException` (`errors()`).
- Название, описание, инструкция, маркировка, вид и имена в справочниках — plain text (см. [Тексты](#тексты));
  `code()` и ссылки — как в ответе.

## Переход с 0.1.x

Несовместимые изменения 0.2.0 — в [CHANGELOG.md](CHANGELOG.md#020--2026-10-11), раздел «Изменено». Чаще всего
затрагивают:

- `merchants()` без `apiToken` — `InvalidArgumentException`; публичный каталог — `merchants(public: true)`;
- свои реализации портов: `CategoryRepository::all(bool $refresh = false)`,
  `MerchantRepository::all(bool $public = false, bool $refresh = false)`;
- валюта `RUR` из ответов — теперь `RUB`; `Offer::charge()` — `null` вместо нуля;
- офферы с ценой 0 — в `skipped()`, на странице может быть меньше `limit` офферов;
- поиск после таймаута повторяется меньшими страницами (`Config::searchFallbackLimit`, `null` — выключить);
- `TransportFactory::fromConfig()`: `rangeHosts` — третьим параметром.

## Кэширование

Категории и магазины кэшируются через `CacheStore`. Внутри TTL документ отдаётся без запроса; после TTL —
условный запрос (`If-None-Match`/`If-Modified-Since`), на 304 копия продлевается. При сетевой ошибке, 5xx или
невалидном ответе возвращается сохранённая копия, а сама копия не меняется.

```php
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;
use Webreboot\GdeSlon\Infrastructure\Cache\Psr16CacheStore;

GdeSlon::create(cache: new FileCacheStore('/var/cache/gdeslon'));
GdeSlon::create(cache: new Psr16CacheStore($psr16Cache));   // psr/simple-cache
(new FileCacheStore('/var/cache/gdeslon'))->directory();     // '/var/cache/gdeslon' — как передан, ФС не трогается
```

Откуда взяты данные, говорит `origin()` у `CategoryTree` и `MerchantList` (`Domain\Shared\DataOrigin`):

| `origin()` | Когда | `isCurrent()` |
|------------|-------|---------------|
| `Fetched` | загружены из API: кэша нет, копии нет или пришла новая версия; также у снимка, созданного вручную | `true` |
| `Cached` | копия в пределах TTL, без запроса | `true` |
| `Revalidated` | API ответил 304 на условный запрос, копия продлена | `true` |
| `Stale` | API недоступен (сеть, 5xx, невалидный ответ), отдана устаревшая копия | `false` |

```php
$tree = $gdeslon->categories();
if (!$tree->origin()->isCurrent()) {
    $logger->warning('категории из сохранённой копии: API недоступен');
}

$tree = $gdeslon->categories(refresh: true);                 // Fetched или Revalidated
$merchants = $gdeslon->merchants(refresh: true);
$catalog = $gdeslon->merchants(public: true, refresh: true);
```

- `Cached` считается актуальным при любом TTL: «актуально» значит «в пределах заданной вами свежести». Нужна
  гарантия сверки с API — `refresh: true`.
- `refresh: true` идёт в API даже при свежей копии, с её валидаторами (только имеющимися: у магазинов — только
  `If-None-Match`); 304 продлевает копию, новая версия заменяет её. Устаревшую копию `refresh` не отдаёт: любой сбой
  (сеть, таймаут, HTTP-ошибка любого статуса, невалидный ответ, неверный токен) — исключение, копия не тронута, и
  следующий обычный вызов отдаст её как раньше. Без кэша `refresh` ничего не меняет.
- Ошибка записи в кэш — не ошибка загрузки: данные возвращаются, копия просто не обновится (и при `refresh`).
- `withOrigin(DataOrigin $origin)` возвращает копию снимка с другим происхождением — для собственных реализаций
  портов; исходный объект не меняется.

- Кэш магазинов содержит партнёрские ссылки вебмастера: каталог должен быть доступен только процессу приложения
  (не общий `/tmp`). Токен в кэш не записывается, кэши разных токенов разделены; публичный каталог
  (`merchants(public: true)`) — одна запись на все токены.
- Документ магазинов ~1,5 МБ (публичный каталог ~2 МБ): хранилища с лимитом записи 1 МБ (Memcached по умолчанию) его не сохранят, SDK
  продолжит работу без кэша.

## HTTP

### Загрузка частями

Из части российских сетей TCP-соединение с `api.gdeslon.ru` (Hetzner) зависает после ~16 КБ тела. Поэтому ответы
этого хоста по умолчанию загружаются частями по 16 КиБ через HTTP Range, каждая часть — отдельным соединением
(`Connection: close`). Целостность проверяется по общему размеру из `Content-Range` и ETag/Last-Modified каждой
части; зависшая часть повторяется с меньшим размером, а после таймаута соединения — тем же; если попытки кончились
на таймауте соединения — `ConnectTimeoutException`. Сервер без поддержки Range отвечает целиком (`200`). Сама
загрузка частями первую часть не повторяет, но её таймаут соединения повторит внешний слой транспорта — `connectRetries`
раз (см. [Собственный транспорт](#собственный-транспорт)). Он же перезапускает загрузку с начала, если часть
продолжения исчерпала свои попытки на таймауте соединения: уже полученные части загружаются заново, а одна такая
часть в худшем случае ждёт `connectTimeout` × 3 × (`connectRetries` + 1). В сообщении тогда два счётчика: «после 3
попыток» — попытки части, «попыток: N» — перезапуски.

```php
new Config(rangeChunkSize: 8192);   // меньше часть
new Config(rangeChunkSize: null);   // один запрос
```

`CurlTransport` открывает новое соединение на каждый запрос. Лимит ~16 КБ действует на всё TCP-соединение, а не на
ответ: в одном keep-alive соединении к `api.gdeslon.ru` уже третий ответ поиска зависает, поэтому переиспользование
соединений не включается.

### Собственный транспорт

Точка расширения — `Webreboot\GdeSlon\Infrastructure\Http\HttpTransport::send(HttpRequest): HttpResponse`. Свой
транспорт подключается одним из трёх способов.

**Полная замена** (прокси, адаптер к PSR-18-клиенту): транспорт передаётся в `GdeSlon::create(transport: $transport)`
и используется как есть — `timeout`, `userAgent` и `rangeChunkSize` из `Config` к нему не применяются, загрузки
частями нет.

**Декоратор снаружи** видит логические запросы и готовые ответы: после загрузки частями — один собранный `200` на
запрос. Подходит для записи ответов и логов вызовов API:

```php
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;

$config = new Config(apiToken: getenv('GDESLON_API_TOKEN') ?: null);
$gdeslon = GdeSlon::create($config, new RecordingTransport(TransportFactory::fromConfig($config), $dir));
```

**Декоратор внутри загрузки частями** видит каждую часть: запросы с `Range`, ответы `206`, повторы зависших частей.
Подходит для метрик соединений:

```php
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;

$transport = TransportFactory::fromConfig($config, inner: new MetricsTransport(CurlTransport::fromConfig($config)));
$gdeslon = GdeSlon::create($config, $transport);
```

`TransportFactory::fromConfig($config)` собирает тот же транспорт, что и `GdeSlon::create($config)`: cURL с
`timeout`, `connectTimeout` и `userAgent` из `Config`, обёрнутый в загрузку частями по `rangeChunkSize`, а снаружи —
в `RetryOnConnectTimeoutTransport`: GET и HEAD, упавшие с `ConnectTimeoutException`, отправляются ещё раз,
`connectRetries` раз; POST (в том числе заявка на потерянный заказ) не повторяется никогда. С `inner:` вместо cURL
оборачивается ваш транспорт; при `rangeChunkSize: null` загрузки частями нет, при `connectRetries: 0` — повтора.
Транспорт, переданный в `GdeSlon::create($config, $transport)` без фабрики, повтором не оборачивается.
`$inner` не подгоняется под `Config`: таймауты и User-Agent у него свои — собирайте его из того же `Config`, как в
примере. Третий параметр `rangeHosts` задаёт хосты загрузки частями (по умолчанию `api.gdeslon.ru`).

Пример декоратора (`RecordingTransport` и `MetricsTransport` выше устроены так же):

```php
use Psr\Log\LoggerInterface;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;

final class LoggingTransport implements HttpTransport
{
    public function __construct(private readonly HttpTransport $inner, private readonly LoggerInterface $logger)
    {
    }

    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $started = microtime(true);
        $response = $this->inner->send($request);
        $this->logger->info(sprintf('%s %s: HTTP %d, %.2f с', $request->method(), $request->maskedUri(), $response->statusCode(), microtime(true) - $started));

        return $response;
    }
}
```

- В лог пишите только `$request->maskedUri()`: `uri()`, `url()`, `query()`, `headers()` и сам объект запроса содержат
  токен или ключ.
- Тела ответов заказов и потерянных заказов, тело запроса подачи заявки — персональные данные покупателей и данные
  продаж: не сохраняйте их без необходимости и не отправляйте в общие логи.
- Внутренний декоратор не кэширует по URL: части одного документа различаются только заголовком `Range`, а повтор
  зависшей части должен уйти в сеть.

Требования к реализации:
- метод и тело (`HttpRequest::body()`) отправляются байт-в-байт;
- редиректы не выполняются, TLS проверяется;
- под загрузкой частями (`inner:` или `new RangeTransport($transport)`) — соблюдение `HttpRequest::timeout()` и отказ
  от переиспользования соединения при `Connection: close`;
- истечение времени: соединение не установлено (DNS, TCP, TLS, туннель прокси; запрос не отправлен) —
  `ConnectTimeoutException`, ответ не дождались после отправки запроса — `TimeoutException`. На этом различии держится
  [повтор поиска меньшими страницами](#повтор-меньшими-страницами): `TimeoutException` его запускает,
  `ConnectTimeoutException` — нет. Не уверены в фазе — бросайте `TimeoutException`, повтор сохранится;
- параметр запроса помечен атрибутом: в запросе токен или ключ, атрибут с интерфейса не наследуется, а без него на
  PHP 8.2+ запрос попадёт в аргументы трассы исключений транспорта.

```php
public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
```

В адаптере к PSR-18-клиенту не переводите Guzzle `ConnectException` в `ConnectTimeoutException`: Guzzle бросает его на
любую ошибку cURL 28, в том числе на обрыв ответа после ~16 КБ, и повтор поиска перестанет работать.

`CurlTransport` относит таймаут к соединению, если cURL не отправил запрос и не получил ни байта ответа.
На старых libcurl (проверено на 7.74; на 8.14 и новее — различается) при HTTPS через прокси байты `CONNECT`
считаются отправленным запросом, поэтому таймаут туннеля прокси приходит как `TimeoutException` и запускает повтор —
как до появления `ConnectTimeoutException`.

## Исключения

Все исключения реализуют `Webreboot\GdeSlon\Exception\GdeSlonException`. Сообщение содержит метод, URL без
секретов и причину.

| Исключение | Условие |
|------------|---------|
| `TransportException` | DNS, отказ соединения, TLS, обрыв ответа (`curlErrorCode()`) |
| `TimeoutException` | истёк таймаут: ответ не дождались; наследует `TransportException` |
| `ConnectTimeoutException` | соединение не установлено за `connectTimeout` или общий `timeout` (DNS, TCP, TLS, туннель прокси), запрос не отправлен — для GET и HEAD после `connectRetries` повторов; наследует `TimeoutException` |
| `HttpException` | статус вне 2xx (`statusCode()`, `responseSnippet()`) |
| `AuthenticationException` | 401/403 или учётные данные не приняты; наследует `HttpException` |
| `UnexpectedResponseException` | невалидный JSON/XML, неожиданная структура документа |
| `InvalidArgumentException` | невалидные настройки, критерии или значения (до запроса) |
| `CategoryNotFoundException`, `MerchantNotFoundException` | `get()` с несуществующим ID |
| `Domain\Claims\*Exception`, `CouponCriteriaRejectedException` | исходы заявок и отказ в фильтре купонов |
| `Interface\Postback\PostbackException` | невалидный или неаутентифицированный postback (`responseStatus()`) |

Токены и ключи не попадают в сообщения исключений, а на PHP 8.2+ — и в аргументы трассы (`getTrace()`): параметры с
секретами помечены `#[\SensitiveParameter]`. На PHP 8.1 атрибут не действует — включите `zend.exception_ignore_args=On`
(так в `php.ini-production`), иначе `var_export($e->getTrace())` и логгеры, которые пишут аргументы трассы, покажут
токен. Исключения пакета не сериализуйте: на 8.2+ `serialize($e)` бросает исключение, если в трассе есть скрытый
аргумент (`SensitiveParameterValue`), на 8.1 — если в ней запрос с токеном или ключом. Для логов и очередей берите
`getMessage()` или `(string) $e`.

Также на PHP 8.2+ скрыты данные покупателей и продаж: тела ответов API (заказы, заявки), новая заявка с чеком,
фильтр sub_id, параметры и тело postback. Ошибка разбора JSON приходит без `getPrevious()`: в трассе `JsonException`
было бы всё тело ответа, а текст ошибки JSON есть в сообщении. Сообщение может содержать значение, не прошедшее
проверку (дату или сумму заявки, неверное поле postback), — учитывайте это, если логи доступны не только вам. Свой
источник заявок (`LostOrderClaims`) или заказов (`OrderRepository`) помечайте так же — атрибут интерфейса не
наследуется:

```php
public function submit(#[\SensitiveParameter] NewLostOrderClaim $claim): LostOrderClaim
```

API иногда возвращает переданный токен в теле ошибки (поиск на токен неверного формата отвечает 404 с эхом значения).
SDK заменяет такие значения на `***` в `responseSnippet()` и сообщениях, но только значения длиной от 4 символов —
короче не маскируются, чтобы не портить текст случайными совпадениями.

## Тестирование в приложении

`Webreboot\GdeSlon\Testing` — фейки для тестов кода, который использует SDK: без сети, без реального времени и без
зависимости от PHPUnit. Они входят в пакет, отдельной установки не нужно.

| Класс | Заменяет | Возможности |
|-------|----------|-------------|
| `FakeHttpTransport` | `HttpTransport` | очередь ответов по порядку: `willReturn($status, $body, $headers)` (значение заголовка — строка или список строк), `willThrow($exception)`, `willFail($curlErrorCode, $message)`, `willTimeOutOnConnect($message)`; запросы — `requests()`, `lastRequest()` |
| `FrozenClock` | `Clock` | `FrozenClock::at('2026-10-07 10:00:00')` (без зоны — UTC), `now()`, `advance($seconds)` |
| `InMemoryCacheStore` | `CacheStore` | кэш в памяти, `all()`; `failWrites()` — запись отказывает |
| `FakeCategoryRepository`, `FakeMerchantRepository` | порты каталога | отдают переданный снимок; `calls()`, `lastRefresh()`, `lastPublic()` |
| `FakeProductCatalog` | `ProductCatalog` | очередь страниц-замыканий `fn (SearchCriteria $c): SearchResult`; `criteria()` |
| `FakeOrderRepository` | `OrderRepository` | переданные заказы на любой фильтр; `criteria()` |
| `FakeCouponFeed` | `CouponFeed` | переданные купоны с отбором по магазинам фильтра; `criteria()` |
| `FakeLostOrderClaims` | `LostOrderClaims` | заявки, отбор по магазину, состоянию и статусу; период не учитывается; поданная заявка получает следующий свободный номер от 9001 и видна в `find()` и `get()`; `submitted()`, `criteria()`, `failSubmitWith($exception)` |

Весь путь SDK — запрос, разбор ответа, исключения — проверяется на фейковом транспорте:

```php
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;

final class OfferImportTest extends TestCase
{
    public function testImportsOffers(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, (string) file_get_contents(__DIR__ . '/fixtures/search.xml'));
        $gdeslon = GdeSlon::create(new Config(apiToken: 'test-token'), $transport);

        $page = $gdeslon->search('iphone');

        self::assertCount(7, $page);
        self::assertSame('iphone', $transport->lastRequest()->query()['q'] ?? null);
    }
}
```

`(new FakeHttpTransport())` — в скобках для PHP 8.1–8.3: вызов метода у `new` без скобок появился в PHP 8.4.

Ошибки API и сети:

```php
$config = new Config(apiToken: 'test-token', searchFallbackLimit: null);

(new FakeHttpTransport())->willReturn(403, 'This affiliate token does not exists');   // AuthenticationException
(new FakeHttpTransport())->willReturn(200, '<yml_catalog><offers><offer');            // UnexpectedResponseException
(new FakeHttpTransport())->willFail(28, 'Operation timed out');                       // TimeoutException
(new FakeHttpTransport())->willFail(7);                                                // TransportException
(new FakeHttpTransport())->willTimeOutOnConnect();                                    // ConnectTimeoutException
```

`willFail()` принимает код ошибки cURL: 28 — `TimeoutException`, остальные — `TransportException`; текст сообщения
не разбирается. Таймаут соединения — `willTimeOutOnConnect()`: `ConnectTimeoutException`, поиск его не повторяет.
Исключение создаётся при отправке, с методом и адресом запроса, как у `CurlTransport`. Поиск после таймаута повторяется
меньшими страницами ([подробнее](#повтор-меньшими-страницами)), и каждый под-запрос берёт следующий ответ из
очереди; чтобы проверить сам таймаут, отключите повтор: `searchFallbackLimit: null`.

Кэш и время:

```php
use Webreboot\GdeSlon\Testing\FrozenClock;
use Webreboot\GdeSlon\Testing\InMemoryCacheStore;

$transport = (new FakeHttpTransport())
    ->willReturn(200, $categoriesJson, ['ETag' => '"v1"'])
    ->willReturn(304, '', ['ETag' => '"v1"']);
$clock = FrozenClock::at('2026-10-07 10:00:00');
$gdeslon = GdeSlon::create(new Config(cacheTtl: 3600), $transport, new InMemoryCacheStore(), $clock);

$gdeslon->categories();   // запрос, DataOrigin::Fetched
$gdeslon->categories();   // из кэша, DataOrigin::Cached
$clock->advance(3600);
$gdeslon->categories();   // условный запрос, 304 — DataOrigin::Revalidated
```

Подмена порта целиком, без HTTP:

```php
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Testing\FakeCategoryRepository;

$tree = new CategoryTree($categories);
$gdeslon = new GdeSlon($transport, categories: new FakeCategoryRepository($tree));
```

- Фикстуры берите из живых ответов API (сохраните ответ один раз — например, декоратором из «Собственный
  транспорт») и обезличьте: номера заказов, суммы, sub_id, код вебмастера в партнёрских ссылках (`/cm/…/`, `/cf/…/`),
  токены купонов (`/ck/…/`). Тело отдаётся байт в байт — кодировка и переводы строк как в API.
- В тестах — фиктивный токен (`'test-token'`): запрос с токеном виден в `requests()` и в `var_export()` фейка, как у
  самого `HttpRequest`.
- Неожиданный вызов (пустая очередь `FakeHttpTransport` или `FakeProductCatalog`) и `lastRequest()` без запросов —
  `\LogicException`, а не `GdeSlonException`: это ошибка теста, и `catch (GdeSlonException)` в вашем коде её не
  скроет. У `FakeHttpTransport` в сообщении — метод и адрес запроса без токена.

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
  Testing/                  фейки портов, транспорта и часов для тестов приложения; остальной код от них не зависит
```

## Совместимость

Публичный API — фасад `GdeSlon`, `Config`, доменные объекты, порты, `CacheStore`/`HttpTransport` и их реализации,
`TransportFactory`, `SystemClock`, `Interface\Postback`, фейки `Testing\*`. Классы и методы с `@internal` в него не
входят.

- Новые параметры конструкторов и методов (в том числе фейков `Testing\*`) добавляются только в конец списка;
  вызывайте конструкторы с именованными аргументами.
- Тексты `\LogicException` фейков `Testing\*` в API не входят: проверяйте класс исключения, а не сообщение.
- До 1.0 несовместимые изменения возможны в минорных версиях и фиксируются в [CHANGELOG.md](CHANGELOG.md).
- Стабильность CLI и MCP описана в [docs/cli.md](docs/cli.md#совместимость) и [docs/mcp.md](docs/mcp.md#совместимость).

## Разработка

```bash
composer install
composer check   # phpstan level max + PHPUnit
```

Тесты не обращаются к сети: адаптеры проверяются на фейковом транспорте и обезличенных фикстурах реальных ответов.
`phpunit.xml.dist` направляет cURL на прокси-заглушку `127.0.0.1:9` (кроме `127.0.0.1` и `localhost`): случайный
запрос во внешнюю сеть сразу падает с «Failed to connect».
Фейки портов, транспорта и часов лежат в `src/Testing` (публичный API, ими пользуются и тесты пакета), служебные
помощники тестов — в `tests/Support`.
Справочник API с проверенными фактами — [docs/gdeslon-api/](docs/gdeslon-api/README.md).

## Лицензия

MIT, © webreboot. «Где Слон?» — название и товарный знак их владельца.
