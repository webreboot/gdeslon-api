# gdeslon-api

PHP-клиент для API вебмастеров партнёрской сети [«Где Слон?»](https://gdeslon.ru/for_affiliates/tools/).

> Ранняя версия (0.1.0-dev): доступны категории товаров, магазины (с кэшем), поиск товаров, заказы, приём postback,
> заявки на потерянные заказы, купоны и CLI `gdeslon`. MCP-сервер — в работе.

- PHP 8.1+, только расширения `curl`, `json`, `libxml`, `simplexml`, `xmlreader` — без сторонних зависимостей.
- Ответы API превращаются в типизированные объекты; ошибки — в исключения с понятным текстом и без токенов.
- TLS всегда проверяется, редиректы не выполняются.

## Установка

```bash
composer require webreboot/gdeslon-api
```

## Категории товаров

Публичный справочник, ключ API не нужен.

```php
use Webreboot\GdeSlon\GdeSlon;

$tree = GdeSlon::create()->categories();   // один HTTP-запрос

$category = $tree->get(1114);
$category->name();                          // 'Женская одежда'
$category->offerCount();                    // 1150082, или null — API сообщает число офферов не для всех категорий

foreach ($tree->roots() as $root) {
    echo $root->id()->value(), ' ', $root->name(), PHP_EOL;
}

$tree->childrenOf(1113);                    // прямые подкатегории
$tree->find(277);                           // null — такой категории нет

$path = array_map(fn ($c) => $c->name(), $tree->breadcrumbs(1231));
echo implode(' › ', $path);                 // Одежда › Детская одежда › Для девочек › Верхняя одежда › Парки
```

В выгрузке «Где Слон?» есть категории, чей родитель отсутствует (`$tree->orphans()`); их хлебные крошки
пропускают недостающие звенья, а полный путь из ID доступен в `$category->path()`.

## Магазины (рекламодатели)

Нужен токен XML API из [настроек кабинета](https://gdeslon.ru/api_settings/xml) — тогда в ответе магазины вебмастера с
готовыми партнёрскими ссылками, тарифами и условиями. Без токена — публичный каталог без ссылок.

```php
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\GdeSlon;

$gdeslon = GdeSlon::create(new Config(apiToken: getenv('GDESLON_API_TOKEN') ?: null));
$merchants = $gdeslon->merchants();                // ~1,4 МБ XML, один запрос

$shop = $merchants->get(105263);                   // ID магазина = merchant_id в поиске и заказах
$shop->name();                                     // 'superstep.ru'
$shop->affiliateLink();                            // 'https://sf.gdeslon.ru/cf/…?erid=…&mid=105263' | null
$shop->commissionSummary();                        // '10,3%' — текст для показа
foreach ($shop->tariffs() as $tariff) {
    echo $tariff->title(), ': ', $tariff->rate(), $tariff->isPercent() ? '%' : ' ₽', PHP_EOL;
}
$shop->isTrafficTypeAllowed('Cashback');           // true | false | null (тип не указан)

$merchants->findByDomain('https://www.komus.ru/catalog');  // [komus.ru]
$merchants->search('ali');                         // по названию и домену, без учёта регистра
$merchants->inCategory(50);                        // категория магазинов (не товарная категория)
```

API не сообщает о неверном токене — отвечает публичным каталогом. Поэтому если токен задан, а в ответе нет ни одной
партнёрской ссылки, `merchants()` бросает `AuthenticationException` (неверный токен или нет подключённых программ;
`statusCode()` в этом случае 200). Токен не попадает в сообщения ошибок, `var_dump`/`print_r` клиента и ключи кэша;
клиент и `Config` с токеном не сериализуются (`InvalidArgumentException`).

Если данные отдельного магазина в ответе битые, он пропускается, а причина видна в `$merchants->skipped()` — остальные
магазины доступны; такой ответ не записывается в кэш. Если не разобралась ни одна запись, или документ битый целиком, —
`UnexpectedResponseException` (или сохранённая копия из кэша, если она есть).

## Поиск товаров

Нужен токен XML API. Результат — страница офферов с готовыми партнёрскими ссылками.

```php
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\GdeSlon;

$gdeslon = GdeSlon::create(new Config(apiToken: getenv('GDESLON_API_TOKEN') ?: null));

foreach ($gdeslon->search('iphone -pink') as $offer) {   // ключевые слова как есть: AND, OR, скобки, минус-слова
    echo $offer->name(), ': ', $offer->price(), PHP_EOL;   // «Смартфон … : 89990 RUR» (сумма — строкой, без float)
    $offer->charge();          // вознаграждение вебмастера в деньгах (Money|null; «0» — API его не рассчитало)
    $offer->affiliateLink();   // https://af.gdeslon.ru/cm/…/?mid=…&goto=…
    $offer->merchantId();      // = ID в $gdeslon->merchants()
}

$criteria = new SearchCriteria(
    query: 'платье',
    merchants: [107054, 111211],     // магазины (через MerchantId или int)
    excludedCategories: [26],        // товарные категории; фильтр без дочерних категорий
    sort: OfferSort::Price,          // по убыванию цены
    limit: 20,                       // 1..100, по умолчанию 10
);
for ($page = $gdeslon->search($criteria); ; $page = $gdeslon->search($next)) {
    // … $page->offers() …
    $next = $page->nextPage();       // null — страниц больше нет (API отдаёт не глубже 10 000 офферов)
    if ($next === null) {
        break;
    }
}
$page->total();     // сколько всего найдено; null — неизвестно (без фильтров API сообщает «≥ 1 000 000»)
$page->skipped();   // причины пропуска офферов с битыми данными
```

Ошибки: неверный токен — `AuthenticationException`; неверные критерии (`limit` > 100, страница за пределом, ID ≤ 0) —
`InvalidArgumentException` до запроса; поиск без токена — `InvalidArgumentException` без запроса.

> Из части российских сетей ответ поиска больше ~16 КБ обрывается (Range API не поддерживает) — даже `limit: 10` при
> длинных описаниях товаров. Тогда `search()` завершится `TimeoutException` с подсказкой уменьшить `limit`; сам
> `limit` библиотека не меняет. Из таких сетей используйте `limit` ≤ 5 и меньший `Config::timeout`.

## Заказы (продажи)

Нужны ID пользователя и ключ **API по продажам** (https://gdeslon.ru/api_settings/orders) — это не токен XML API.

```php
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\GdeSlon;

$gdeslon = GdeSlon::create(new Config(
    userId: getenv('GDESLON_USER_ID') ?: null,
    apiKey: getenv('GDESLON_API_KEY') ?: null,
));

$orders = $gdeslon->orders();   // созданные за последние 30 дней (по московскому «сегодня»)

// изменённые за неделю, подтверждённые или выплаченные — для синхронизации статусов
$orders = $gdeslon->orders(new OrderCriteria(
    dateField: OrderDateField::LastUpdated,   // Transition, Created, LastUpdated, Confirmed, Accrued
    until: '2026-10-07',                      // последний день периода включительно; по умолчанию — сегодня
    days: 7,                                  // 1..3660
    states: [OrderState::Confirmed, OrderState::Paid],
    merchant: 2573,                           // один магазин на запрос
    subId: 'blog',
));

foreach ($orders as $order) {
    $order->id();          // OrderId — ID заказа в «Где Слон?»
    $order->state();       // New, Cancelled, Pending, Confirmed, Paid
    $order->reward();      // вознаграждение вебмастера (Money)
    $order->amount();      // сумма заказа (Money|null — у лида может не быть)
    $order->subId();
    $order->lastUpdatedAt();
}
$orders->find('81234567');
$orders->skipped();       // причины пропуска заказов с битыми данными
```

Особенности API:
- запрос идёт 4–6 секунд, пагинации нет — весь период приходит одним ответом: берите короткие периоды, а для
  синхронизации — `LastUpdated`; изменение статуса попадает в API на следующий день;
- на неверный фильтр API отвечает 500 без подробностей, поэтому `OrderCriteria` проверяет всё до запроса
  (`InvalidArgumentException`); несколько магазинов одним запросом API не принимает;
- форма непустого ответа пока проверена только по документации: если `skipped()` не пуст или заказы не разобрались
  (`UnexpectedResponseException`), пожалуйста, создайте issue — без персональных данных;
- свой HTTP-транспорт обязан передавать тело запроса (`HttpRequest::body()`) — см. «Настройки»;
- валюта заказа приводится к верхнему регистру как есть (`rub` → `RUB`); магазины и поиск отдают `RUR` —
  `Money::equals()` их не сравнивает.

## Postback (уведомления о заказах)

«Где Слон?» сам вызывает ваш URL при смене статуса заказа (настройка — https://gdeslon.ru/postbacks/). Библиотека
проверяет секрет, разбирает GET-параметры, форму, JSON или XML и отдаёт доменную конверсию.

В кабинете: метод GET или POST (`params`, `json`, `xml`), параметры «Получаемое имя → макрос» и заголовок с секретом,
например `X-Gdeslon-Secret` → длинное случайное значение. URL — только HTTPS: подписи у postback нет, секрет — единственная
защита от поддельных уведомлений.

```php
use Webreboot\GdeSlon\Interface\Postback\HeaderSecret;
use Webreboot\GdeSlon\Interface\Postback\PostbackException;
use Webreboot\GdeSlon\Interface\Postback\PostbackFields;
use Webreboot\GdeSlon\Interface\Postback\PostbackReceiver;
use Webreboot\GdeSlon\Interface\Postback\PostbackRequest;

$receiver = new PostbackReceiver(
    new HeaderSecret('X-Gdeslon-Secret', (string) getenv('GDESLON_POSTBACK_SECRET')),   // не короче 16 символов
    new PostbackFields(['order_id' => 'someOrderId', 'profit' => 'myProfit']),           // если в кабинете свои имена
);

try {
    $postback = $receiver->receive(PostbackRequest::fromGlobals());
} catch (PostbackException $e) {
    http_response_code($e->responseStatus());   // 401 секрет, 400 данные, 405 метод, 413 размер, 415 Content-Type
    header('Content-Type: text/plain; charset=utf-8');   // простой текст, не HTML
    exit($e->getMessage());                     // без секрета и sub_id — видно в окне теста кабинета
}

$conversion = $postback->conversion();
$conversion->merchantId();      // MerchantId
$conversion->state();           // OrderState: New, Cancelled, Pending, Confirmed, Paid
$conversion->orderId();         // OrderId|null — ID заказа в «Где Слон?»
$conversion->subId(2);          // sub_id2
$conversion->reward();          // '123.45' — заработок вебмастера строкой (валюта не документирована)
$conversion->clickedAt();       // DateTimeImmutable|null
$conversion->deduplicationKey(); // «gdeslon:900001:3» — UNIQUE-ключ в БД: повтор того же статуса не задвоит начисление
foreach ($postback->warnings() as $warning) {
    error_log('gdeslon postback: ' . $warning);  // битые необязательные поля (стали null), без пользовательских данных
}
echo 'OK';                                       // 200
```

Laravel/Symfony — свой запрос без суперглобалов (в Laravel исключите маршрут из CSRF):

```php
$postback = $receiver->receive(new PostbackRequest(
    method: $request->getMethod(),
    headers: $request->headers->all(),
    query: (string) $request->server->get('QUERY_STRING', ''),
    body: $request->getContent(),
    form: $request->request->all(),
));
// или из PSR-7 (нужен psr/http-message): PostbackRequest::fromPsr7($psrRequest)
```

Важно:
- обязательны только `merchant_id` и `state`; битое необязательное поле становится null с предупреждением, а не ошибкой —
  конверсия не теряется;
- все значения (sub_id, user_agent, offer_name…) — недоверенный ввод: экранируйте при выводе и используйте
  подготовленные запросы к БД;
- `Authorization` Apache/CGI без настройки не передают в PHP, а nginx отбрасывает заголовки с «_» — используйте
  `X-Gdeslon-Secret`;
- XML принимается только в UTF-8, без DOCTYPE; для multipart нужна разобранная форма (`form`), а PHP в `$_POST` заменяет
  точки и пробелы в именах параметров на «_» — называйте параметры латиницей, цифрами и «_»;
- формат времени и сумм в реальном postback ещё не проверен (`docs/gdeslon-api/postback.md`): время — ISO 8601,
  «Y-m-d H:i:s» (без пояса — Москва) или unix-время, суммы — «123.45»; если `warnings()` не пуст, пожалуйста, создайте
  issue без персональных данных;
- postback — сигнал, а не бухгалтерия: суммы сверяйте через `orders()`.

## Потерянные заказы (заявки)

Заказ не попал в статистику — заявка рекламодателю со сканом чека (FAQ 74). Нужен токен XML API.

```php
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Claims\AttachmentFile;

$gdeslon = GdeSlon::create(new Config(apiToken: getenv('GDESLON_API_TOKEN') ?: null, timeout: 120.0));

foreach ($gdeslon->lostOrders(new LostOrderCriteria(merchant: 2573, orderStatus: LostOrderStatus::Waiting)) as $claim) {
    echo $claim->id(), ' ', $claim->orderNumber(), ' ', $claim->orderTotal(), ' ', $claim->claimState()->value, PHP_EOL;
}
$gdeslon->lostOrder(5796);   // null — нет такой заявки

try {
    $created = $gdeslon->submitLostOrderClaim(new NewLostOrderClaim(   // ⚠️ создаёт РЕАЛЬНУЮ заявку
        orderNumber: 'GS123L',
        orderDate: '2026-09-24',            // не старше 3 месяцев
        orderTotal: '554.34',               // два знака после точки, без валюты
        merchant: 2573,
        attachment: AttachmentFile::load('/path/receipt.pdf'),   // JPEG, PNG или PDF до 10 МиБ
        description: 'Заказ после перехода с моего сайта',
    ));
} catch (DuplicateLostOrderClaimException $e) {
    $e->existing();       // заявка на этот номер заказа у магазина уже есть — новая не отправлена
} catch (LostOrderValidationException $e) {
    $e->errors();         // ['order_date' => ['…']] — заявка не создана
} catch (LostOrderClaimUnconfirmedException $e) {
    // ответа нет (таймаут, обрыв, 5xx) — заявка МОГЛА быть создана: не повторяйте, сначала проверьте lostOrders()
}
```

- Перед созданием библиотека ищет заявку на тот же номер заказа этого магазина (`checkDuplicates: false` — без
  проверки). Проверка не защищает от параллельных вызовов (два воркера очереди) — сериализуйте отправку. Запрос создания
  никогда не повторяется автоматически; после `LostOrderClaimUnconfirmedException` (`wasCreated()`, `claimId()`) не
  повторяйте, а спустя время проверьте `lostOrders()`.
- Фильтры по статусам дополнительно проверяются на клиенте: документированные фильтры API принимает с любым значением.
- Магазин в фильтре — из справочника магазинов «Где Слон?», иначе API отвечает 400 (`LostOrderValidationException`).
- Форма ответа с заявками пока проверена только по документации: если `skipped()` не пуст, создайте issue без
  персональных данных.

## Купоны и промокоды

Действующие купоны магазинов вебмастера с маркировкой рекламы. Нужен токен XML API.

```php
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\GdeSlon;

$gdeslon = GdeSlon::create(new Config(apiToken: getenv('GDESLON_API_TOKEN') ?: null));

$coupons = $gdeslon->coupons(new CouponCriteria(merchants: [99157], kinds: [1]));   // фильтр — необязателен
foreach ($coupons->activeAt(new DateTimeImmutable()) as $coupon) {
    $coupon->merchantName();     // 'elementaree.ru'
    $coupon->name();             // 'Скидка 33% на первый заказ…'
    $coupon->code();             // промокод или null (акция без кода)
    $coupon->kind()->name();     // 'скидка на заказ'
    $coupon->endsAt();           // DateTimeImmutable, Europe/Moscow
    $coupon->affiliateLinkWithCode() ?? $coupon->affiliateLink();   // ⚠️ см. ниже
    $coupon->adMarking();        // «Реклама. Рекламодатель … erid …» — показывайте рядом со ссылкой
}
$coupons->kinds();     // справочник видов (ID для фильтра kinds)
$coupons->skipped();   // купоны с битыми данными
```

- ⚠️ **API кладёт токен XML API в каждую партнёрскую ссылку купона** (`http://xf.gdeslon.ru/ck/<токен>/<id>`).
  Опубликованная ссылка раскрывает токен, которым читаются магазины и создаются заявки на потерянные заказы. Библиотека
  ссылки не перестраивает (сломалась бы атрибуция) и не кэширует купоны; в `var_dump` токен в ссылках скрыт, но
  `serialize()`, `var_export()` и JSON со ссылками хранят его открытым текстом — кэшируйте купоны только там, где
  допустимо хранить токен. Уточните у поддержки «Где Слон?», как публиковать купоны без токена.
- Публикуя купон как рекламу, показывайте маркировку `adMarking()` (erid) — она есть только в XML-выгрузке, которую
  использует библиотека.
- Ответ — вся выгрузка (около 1 МБ, 3–4 секунды), пагинации нет. Магазин, не подключённый вебмастеру, в фильтре —
  `CouponCriteriaRejectedException` (`errors()`).

## CLI

`vendor/bin/gdeslon` — те же возможности из командной строки. Ключи — только из окружения (или `--env-file`), в выводе
токен заменяется на `***`.

```bash
export GDESLON_API_TOKEN=…                      # https://gdeslon.ru/api_settings/xml
vendor/bin/gdeslon categories 1 --depth=1
vendor/bin/gdeslon merchants --search=komus
vendor/bin/gdeslon search платье красное --limit=20 --sort=price
vendor/bin/gdeslon coupons --active --format=json
vendor/bin/gdeslon lost-orders submit --merchant=2573 --order-number=GS123L --order-date=2026-09-24 \
    --order-total=554.34 --attachment=receipt.pdf --dry-run

export GDESLON_USER_ID=… GDESLON_API_KEY=…      # https://gdeslon.ru/api_settings/orders
vendor/bin/gdeslon orders --days=7 --state=confirmed,paid
```

`--format=json` — стабильная схема для скриптов; коды выхода различают неверный вызов (2), нет ключей (3) и исходы
заявки (4 — не повторять, 5 — уже есть). `lost-orders submit` создаёт реальную заявку и спрашивает подтверждение.
Приём postback — только в коде (`PostbackReceiver`), в CLI его нет. Все команды, опции, поля JSON и предупреждения
для скриптов — в [docs/cli.md](docs/cli.md).

## Кэш

Категории и магазины можно кэшировать. Свежая копия отдаётся без запроса; после срока (`cacheTtl` для категорий —
сутки, `merchantCacheTtl` для магазинов — час) библиотека спрашивает сервер условным запросом и, если данные не
изменились, получает пустой ответ 304. При сбое сети или сервера и при битом ответе отдаётся сохранённая копия.

```php
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;

$gdeslon = GdeSlon::create(cache: new FileCacheStore('/var/cache/gdeslon'));
```

Каталог кэша — отдельный, доступный только вашему процессу (не общий `/tmp`): кэш магазинов содержит партнёрские
ссылки вебмастера (токен в кэш не попадает, кэши разных токенов разделены). Список магазинов — ~1,5 МБ: Memcached с
лимитом 1 МБ на запись его не примет, и библиотека просто будет работать без кэша.

Кэш фреймворка (Symfony, Laravel и др.) через PSR-16 — нужен пакет `psr/simple-cache`:

```php
use Webreboot\GdeSlon\Infrastructure\Cache\Psr16CacheStore;

$gdeslon = GdeSlon::create(cache: new Psr16CacheStore($psr16Cache));
```

## Загрузка частями

Из части российских сетей соединение с `api.gdeslon.ru` (хостинг Hetzner) зависает после ~16 КБ, а файл категорий
весит ~156 КБ. Поэтому по умолчанию ответы с этого хоста загружаются частями по 16 КиБ (HTTP Range), каждая —
отдельным соединением; целостность проверяется по ETag и размеру, зависшая часть повторяется меньшим размером.
Сервер, который Range не поддерживает (например, поиск `search.xml`), отвечает целиком — как без этой настройки;
первая часть не повторяется, поэтому при недоступном хосте время отказа то же, что без загрузки частями.

```php
use Webreboot\GdeSlon\Config;

GdeSlon::create(new Config(rangeChunkSize: 8192));   // часть поменьше
GdeSlon::create(new Config(rangeChunkSize: null));   // одним запросом (сеть без ограничения)
```

> Поиск товаров Range не поддерживает — см. раздел «Поиск товаров».

## Настройки

```php
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\GdeSlon;

$gdeslon = GdeSlon::create(new Config(
    timeout: 60.0,          // общий таймаут запроса, с
    connectTimeout: 10.0,   // таймаут соединения, с
    userAgent: 'my-app/1.0',
    rangeChunkSize: 16384,  // загрузка частями с api.gdeslon.ru; null — выключить
    cacheTtl: 86400,        // сколько секунд кэш категорий свежий; 0 — проверять каждый раз
    apiToken: '…',          // токен XML API: магазины вебмастера с партнёрскими ссылками
    merchantCacheTtl: 3600, // сколько секунд кэш магазинов свежий
    userId: '…',            // ID пользователя API по продажам: заказы (задаётся вместе с apiKey)
    apiKey: '…',            // ключ API по продажам
));
```

Свой HTTP-транспорт (прокси, логирование, PSR-18) — реализация `Webreboot\GdeSlon\Infrastructure\Http\HttpTransport`:
`GdeSlon::create(transport: $myTransport)`. Он должен отправлять метод и тело запроса как есть (`HttpRequest::body()`
байт-в-байт, если не null — без тела фильтры заказов потеряются, а API ответит «нет заказов»). Транспорт используется
как есть; загрузку частями можно добавить обёрткой
`new RangeTransport($myTransport)` — тогда транспорт должен соблюдать `HttpRequest::timeout()` и не переиспользовать
соединение при `Connection: close`. Свой источник категорий — `new GdeSlon($transport, $myCategoryRepository)`.

## Ошибки

Все исключения пакета реализуют `Webreboot\GdeSlon\Exception\GdeSlonException`:

| Исключение | Когда |
|------------|-------|
| `TransportException` | нет сети, DNS, отказ соединения, ошибка TLS, обрыв ответа (`curlErrorCode()`) |
| `TimeoutException` | истёк таймаут (подкласс `TransportException`) |
| `HttpException` | статус не 2xx (`statusCode()`, `responseSnippet()`) |
| `AuthenticationException` | 401/403, а также токен XML API или ключ API по продажам не принят (подкласс `HttpException`) |
| `UnexpectedResponseException` | битый или обрезанный JSON, неожиданная форма данных |
| `CategoryNotFoundException` | `$tree->get()` с несуществующим ID |
| `MerchantNotFoundException` | `$merchants->get()` с несуществующим ID |
| `InvalidArgumentException` | неверные настройки или значения |

```php
use Webreboot\GdeSlon\Exception\GdeSlonException;

try {
    $tree = GdeSlon::create()->categories();
} catch (GdeSlonException $e) {
    // в сообщении — метод, адрес без токенов и причина
}
```

## Лицензия

MIT, © webreboot
