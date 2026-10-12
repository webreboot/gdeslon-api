# Изменения

Формат — [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/), версии — [SemVer](https://semver.org/lang/ru/).
До 1.0 публичный API может меняться в минорных версиях; такие изменения отмечаются здесь.

## [Не выпущено]

Поиск переживает зависание частей повтора, а таймаут соединения больше не роняет запрос с первой попытки.

### Добавлено

- `RetryOnConnectTimeoutTransport` — повторяет GET и HEAD после `ConnectTimeoutException` тем же запросом; POST
  (в том числе заявка на потерянный заказ) не повторяется. Исчерпав попытки, бросает `ConnectTimeoutException`
  с «; попыток: N».
- `Config::connectRetries` — число таких повторов, 0..5, по умолчанию 1.
- `Offer::pictures()` — картинки оффера с источником: `Picture` (`url()`, `source()`) и enum `PictureSource`
  (`Original`, `Picture`, `Thumbnail`; значения `original_picture`, `picture`, `thumbnail`) с `isPreferredTo()`.
  Порядок и повторы — как у `pictureCandidates()`; при совпадении адресов остаётся источник выше по приоритету.
- `CategoryTree::isReachable()` и `descendantsOf($id, reachableOnly: true)` — только достижимые категории: путь от
  существующего корня без архивных шагов и пропусков. Отсекает архивные ветки, «сирот» и их потомков; по умолчанию
  `descendantsOf()` не меняется.

### Изменено

- Часть повтора поиска, не уложившаяся в `timeout`, делится ещё раз — по наибольшему делителю её размера, не большему
  половины (5 → 1), — вместо ошибки всей страницы. Под-запросов по-прежнему не больше 20 на страницу, включая деление;
  не хватает — `TimeoutException` сразу. Тексты исключений повтора изменились.
- `SearchResult::effectiveLimit()` для собранной частями страницы — наименьший размер загруженной части.
- `erid()` оффера, магазина и купона берёт из маркировки **первое** подходящее значение (было — последнее), принимает
  `erid=` наравне с пробелом и `:`, требует в значении хотя бы одну цифру и только латиницу, не принимает обрезанный
  токен (`ERID: 2SDn-TEST` больше не даёт `2SDn`, `erid 2SDnjcZ...` — `2SDnjcZ`) и `erid` из адреса внутри маркировки.
  Битый erid в маркировке — как его отсутствие: erid берётся из ссылки.
- `TransportFactory::fromConfig()` (и `GdeSlon::create($config)`) оборачивает транспорт в
  `RetryOnConnectTimeoutTransport`, если `connectRetries > 0`, — в том числе `inner` при `rangeChunkSize: null`, который
  раньше возвращался как есть. При недоступном API GET ждёт около `connectTimeout` × (`connectRetries` + 1).

### Переход с 0.3

Изменения несовместимы с 0.3 в четырёх местах:

- Тесты на `FakeHttpTransport`, где страница поиска падает после повтора: для `limit: 10` теперь нужно три
  `willFail(28)` (10, 5, 1), а не два, иначе `\LogicException` «неожиданный запрос». Либо отключите повтор:
  `searchFallbackLimit: null`.
- Код, который ждёт от `TransportFactory::fromConfig()` `RangeTransport` или переданный `inner` как есть: передайте
  `connectRetries: 0`.
- Тексты исключений повтора поиска и смысл `SearchResult::effectiveLimit()` (наименьшая часть).
- `erid()` для маркировки с несколькими erid, с `erid=` или с обрезанным токеном возвращает другое значение. На
  реальных ответах API 2026-10-11 и 2026-10-12 (2364 маркировки) разницы нет.

## [0.3.0] — 2026-10-11

Таймаут соединения отделён от обрыва ответа: при недоступном API поиск больше не повторяется меньшими страницами.

### Добавлено

- `ConnectTimeoutException` — таймаут установки соединения: DNS, TCP, TLS или туннель прокси не установлены, запрос
  не отправлен. Наследует `TimeoutException`, поэтому `catch (TimeoutException)` ловит оба. `CurlTransport` бросает
  его, если cURL 28 пришёл до отправки запроса и до первого байта ответа; сообщение — «превышено время установки
  соединения». Таймаут туннеля HTTPS-прокси остаётся `TimeoutException`
  на старых libcurl (проверено на 7.74; на 8.14 и новее — различается).
- `FakeHttpTransport::willTimeOutOnConnect()` — таймаут соединения в тестах; `willFail(28, …)` с любым текстом
  по-прежнему даёт `TimeoutException`.

### Изменено

- Повтор поиска меньшими страницами запускает только таймаут чтения. `ConnectTimeoutException` первого запроса
  пробрасывается как есть, без повтора и без подсказки про `limit`: при недоступном API поиск ждёт около
  `connectTimeout`, а не `connectTimeout` × (число под-запросов + 1). На под-запросе повтора — так же.
- Загрузка частями повторяет часть после таймаута соединения тем же размером (после таймаута чтения — как раньше,
  вдвое меньшей), а исчерпав попытки, бросает `ConnectTimeoutException`.
- `TimeoutException` больше не `final`.

## [0.2.0] — 2026-10-11

Отработаны замечания первого потребителя: ссылки с sub_id, erid и ключ оффера, тексты без HTML-сущностей, валюта
`RUB`, повтор поиска при обрыве ответа, поиск по поддереву категорий, свежесть кэша, публичные фейки для тестов.

### Добавлено

- `Offer::pictureCandidates()` — адреса картинок оффера для скачивания с запасными вариантами: `originalPicture` →
  `picture` → `thumbnail`, без пустых и повторов (см. README, «Картинки»).
- `Currency::normalize()` — нормализация кода валюты: пробелы, верхний регистр, `RUR` → `RUB`.
- `Money::plus()`, `compare()`, `isGreaterThan()`, `isLessThan()` — точное сложение и сравнение сумм одной валюты
  без `float` и `bcmath`. Разрядность суммы — по более длинной дроби, без округления; разные валюты —
  `InvalidArgumentException`, а `equals()` для них по-прежнему `false` (см. README, «Суммы»). Второй операнд помечен
  `#[\SensitiveParameter]`: на PHP 8.2+ сумма продажи не попадает в трассу этого исключения.
- `CategoryTree::descendantsOf()` — всё поддерево категории без неё самой: категории, в чьём `path()` есть указанная,
  в порядке `all()`, с архивными и «сиротами» под предком из их пути; несуществующий ID — `CategoryNotFoundException`.
  Поиск по `tid` берёт только саму категорию — рецепт поиска по разделу в README, «Категории».
- MCP `search_offers` — аргумент `include_subcategories`, CLI `search` — флаг `--subcategories`: включённые и
  исключённые категории раскрываются в поддеревья, исключение побеждает; неизвестный ID — `[not_found]` / код 1.
- `AffiliateLink` — партнёрская ссылка как объект: `Offer::link()`, `Merchant::link()` (`?AffiliateLink`),
  `Coupon::link()` и `linkWithCode()`. `withSubId($value, $position = 1)` возвращает новую ссылку с `sub_id`
  (`sub_id2`…`sub_id5`) в конце query или на месте уже стоящего, остальные байты не меняются; `url()`, `subId()`,
  `erid()`, `merchantId()`, `masked()` (сегмент с токеном купона после `/ck/` — `***`). Конструкторы `Offer`, `Merchant`, `Coupon`
  принимают ссылку строкой или `AffiliateLink` (см. README, «Партнёрская ссылка и sub_id»).
- `SubId` — метка источника трафика для исходящих ссылок: `[A-Za-z0-9_-]{1,64}`, `SubId::isValid()`. Это правило SDK:
  лимиты `sub_id` в API не документированы и не подтверждены, метку другого формата дописывайте к `url()` сами.
- `erid()` у оффера, магазина и купона — идентификатор рекламы из маркировки, иначе из ссылки; `null` — erid нет
  нигде (erid магазина в оффер не подставляется). В JSON CLI и MCP — ключ `erid` в конце оффера, магазина (полная и
  короткая форма) и купона.
- `Offer::key()` — `?OfferKey` (`"<ID магазина>:<артикул>"`, `equals()`, `OfferKey::fromString()`) для склейки офферов,
  повторяющихся на соседних страницах поиска; в JSON оффера — ключ `key` в конце (рецепт — README, «Повторы между
  страницами»).
- Повтор поиска меньшими страницами: страница, не загрузившаяся за `timeout` (`TimeoutException`), загружается заново
  частями того же окна — по наибольшему делителю `limit`, не большему `Config::searchFallbackLimit` (по умолчанию 5,
  `null` — выключить), не больше 20 под-запросов. `SearchResult::isDegraded()` и `effectiveLimit()`; `criteria()` и
  `nextPage()` — от исходного запроса. `SearchCriteria::withLimit()`. В JSON CLI и MCP поиска — `effective_limit` и
  `degraded`, в строке статуса CLI — «загружено частями по N» (см. README, «Повтор меньшими страницами»).
- `categories(refresh: true)`, `merchants(refresh: true)`, `merchants(public: true, refresh: true)` — загрузка в обход
  свежести кэша: запрос с валидаторами копии даже при свежей копии, 304 продлевает её, новая версия заменяет. Любой
  сбой — исключение, копия не тронута (устаревшая копия при `refresh` не отдаётся).
- `DataOrigin` (`Fetched`, `Cached`, `Revalidated`, `Stale`; `isCurrent()`) и `origin()` у `CategoryTree` и
  `MerchantList`: откуда данные — из API, из свежей копии, после 304 или из устаревшей копии при недоступном API
  (раньше такая подмена была молчаливой). `withOrigin()` — копия снимка с другим происхождением; созданный вручную
  снимок — `Fetched` (см. README, «Кэширование»).
- `FileCacheStore::directory()` — каталог кэша, как передан в конструктор.
- CLI: флаг `--refresh` у `categories` и `merchants [list]` (сбой — код 1 или 3, копия не тронута); при данных из
  устаревшей копии — предупреждение в stderr, stdout и JSON не меняются. MCP: при устаревшей копии — строка
  `gdeslon mcp: …` в stderr, ответы инструментов не меняются.
- `TransportFactory` и `SystemClock` — публичный API (сняты `@internal`). `TransportFactory::fromConfig($config,
  inner: $transport)` оборачивает свой транспорт в загрузку частями по настройкам `Config` — для декоратора, который
  видит каждую часть (`Range`, `206`, повторы); `TransportFactory::fromConfig($config)` — транспорт, который собирает
  `GdeSlon::create($config)`, для декоратора снаружи. `$inner` не подгоняется под `Config`, при `rangeChunkSize: null`
  возвращается как есть; `GdeSlon::create(transport: $transport)` по-прежнему без загрузки частями (см. README,
  «Собственный транспорт»).
- `Webreboot\GdeSlon\Testing` — фейки для тестов приложения без сети и без зависимости от PHPUnit:
  `FakeHttpTransport` (очередь ответов `willReturn()`, `willThrow()`, `willFail($curlErrorCode)` — 28 даёт
  `TimeoutException`; `requests()`, `lastRequest()`), `FrozenClock` (`at()`, `advance()`), `InMemoryCacheStore`
  (`failWrites()`), `FakeCategoryRepository`, `FakeMerchantRepository`, `FakeProductCatalog`, `FakeOrderRepository`,
  `FakeCouponFeed`, `FakeLostOrderClaims` (поданная заявка получает следующий свободный номер от 9001 и видна в `find()`
  и `get()`). Неожиданный запрос — `\LogicException`, а не `GdeSlonException`; неразборное время в
  `FrozenClock::at()` — `InvalidArgumentException` (см. README, «Тестирование в приложении»).

### Безопасность

- Токены и ключи больше не попадают в аргументы трассы исключений (`getTrace()`, `var_export`, `serialize`) на
  PHP 8.2+: запрос и другие параметры с секретами помечены `#[\SensitiveParameter]`. На PHP 8.1 атрибут не действует —
  включите `zend.exception_ignore_args=On` (см. README, «Исключения»).
- `HttpRequest` с токеном или ключом (секретный параметр query, `Authorization`, учётные данные в адресе) и
  `HeaderSecret` (а с ним `PostbackReceiver`) не сериализуются — `InvalidArgumentException`. Поэтому и `serialize()`
  исключения пакета с таким запросом в трассе бросает исключение вместо строки с токеном.
- Отказ сериализации `Config`, `GdeSlon`, адаптеров, `HttpRequest` и `HeaderSecret` больше не раскрывает токен через
  трассу своего исключения (там был сериализуемый объект как аргумент `serialize()`): у этого исключения аргументов
  в трассе нет. `GdeSlon` со своим несериализуемым транспортом или кэшем (замыкание, клиент Redis) теперь отказывает
  так же — `InvalidArgumentException` пакета вместо исключения PHP с секретами в трассе.
- Данные покупателей и продаж больше не попадают в аргументы трассы исключений на PHP 8.2+: тела ответов API
  (заказы, заявки), `NewLostOrderClaim` с чеком (и значения при её создании: номер, дата, сумма, описание, файл),
  фильтр sub_id, параметры и тело postback. Трасса подачи заявки больше не держит файл чека до 10 МиБ.
- Ошибка разбора JSON (ответ API, тело postback) больше не цепляет `JsonException`: в её трассе на любой версии PHP
  было всё тело ответа как аргумент `json_decode()`.
- Ссылки купонов (в них токен XML API) помечены `#[\SensitiveParameter]` в `new Coupon()` и не попадают в аргументы
  трассы на PHP 8.2+; сообщение об ошибке в ссылке купона — без ссылки. `masked()` скрывает токен и у ссылки с
  дописанным `sub_id`.

### Изменено

- Порты каталога получили параметр `refresh`. Свои реализации нужно обновить, иначе фатальная ошибка объявления
  класса: `CategoryRepository::all(bool $refresh = false): CategoryTree` и
  `MerchantRepository::all(bool $public = false, bool $refresh = false): MerchantList`. Отдавая копию, помечайте её
  `withOrigin()`, например `$tree->withOrigin(DataOrigin::Stale)`.
- Поиск после таймаута делает до 20 дополнительных запросов и ждёт дольше: до `timeout` × (число под-запросов + 1).
  Без `query` склеенная страница может содержать повторы — убирайте их по `Offer::key()`. Подсказка в
  `TimeoutException` поиска — без «увеличьте Config::timeout». Свой `HttpTransport` должен бросать `TimeoutException`
  при истечении времени, иначе повтора не будет. В MCP `outputSchema` у `search_offers` — новые обязательные поля
  `effective_limit` и `degraded`.
- PHP 8.2+: в `getTrace()[*]['args']` исключений пакета вместо запроса, настроек и строк с секретами —
  `SensitiveParameterValue`, а `serialize()` такого исключения (в том числе без секрета, например ошибки `categories()`)
  бросает стандартное `\Exception` PHP. Для логов и очередей берите `getMessage()` или `(string) $e`.
- Свой `HttpTransport`: помечайте `send(#[\SensitiveParameter] HttpRequest $request)` — атрибут интерфейса не наследуется.
  Так же — `LostOrderClaims::submit()` (`NewLostOrderClaim`) и `OrderRepository::find()` (`OrderCriteria`).
- `getPrevious()` теперь `null` у `UnexpectedResponseException` о битом JSON (`categories()`, `orders()`,
  `lostOrders()`, `lostOrder()`, причина `LostOrderClaimUnconfirmedException`), у «Ответ заявки: …» и у
  `InvalidPostbackException` о битом JSON-теле; текст ошибки JSON — в сообщении. На PHP 8.2+ `serialize()` бросает
  исключение и у исключений, в трассе которых есть заявка, сумма или файл чека, фильтр sub_id, заказ, тело ответа или
  данные postback (в трассе — `SensitiveParameterValue`): например, `DuplicateLostOrderClaimException`, ошибки
  `submitLostOrderClaim()`, `orders()`, `AttachmentFile::load()`, валидации `NewLostOrderClaim`, `OrderTotal`,
  `ClaimAttachment`, `OrderCriteria`, `Order`, `Conversion`.
- Валюта `RUR` из ответов API теперь `RUB` — в поиске, заказах и postback (`Money::currency()`, `Conversion::currency()`,
  JSON CLI и MCP). Сравнения с `'RUR'` в своём коде замените на `'RUB'` или `Currency::normalize()`.
- `Offer::charge()` — `null` вместо `Money('0')`, когда API не рассчитало вознаграждение (в ответе `0`); в JSON —
  `"charge": null`.
- Офферы с ценой 0 больше не попадают в `SearchResult::offers()`, а уходят в `skipped()` с причиной «цена 0». На
  странице может быть меньше `limit` офферов или ни одного — листайте по `nextPage()`.
- Необязательный адрес не http(s) — картинки и `productUrl()` оффера, `logoUrl()` магазина — становится `null`,
  запись не пропускается.
- Конструкторы строже: `new Offer()` бросает `InvalidArgumentException` при нулевом `charge` и при адресе картинки
  или `productUrl` не http(s), `new Merchant()` — при `logoUrl` не http(s).
- Предупреждение CLI о пропущенных записях — «Пропущено записей: N» (было «Пропущено записей с битыми данными: N»).
- Ответ поиска 414 (адрес запроса длиннее ~8 КиБ) — `HttpException` с подсказкой сократить списки категорий,
  магазинов или артикулов; в сообщении адрес без query и длина запроса в байтах, `url()` — прежний (без секретов),
  исходное исключение — в `getPrevious()`.
- `merchants()` без `apiToken` больше не отдаёт молча публичный каталог, а бросает `InvalidArgumentException` до
  запроса (как `search()`), даже если в кэше есть свежая копия. Публичный каталог без партнёрских ссылок — явно:
  `merchants(public: true)`; токен в этом режиме не отправляется, даже если задан, а пустой список ссылок не считается
  отклонённым токеном. Копия в кэше, сохранённая 0.1.x без токена, подходит публичному режиму. CLI `merchants` и MCP
  `list_merchants`, `get_merchant`, `list_merchant_categories` без `GDESLON_API_TOKEN` работают как раньше.
- Партнёрская ссылка с пробелами или управляющими символами (внутри, не по краям) теперь отбрасывает запись: оффер,
  магазин или купон уходит в `skipped()`, а `new Offer()`, `new Merchant()`, `new Coupon()` с такой ссылкой бросают
  `InvalidArgumentException`. В живых ответах таких ссылок не встречалось. Пробелы по краям ссылки купона теперь
  обрезаются, как у офферов и магазинов.
- `TransportFactory::fromConfig()`: `rangeHosts` теперь третий параметр (вторым стал `inner`). Позиционный вызов
  `fromConfig($config, ['host'])` даёт `TypeError` — передавайте `rangeHosts:` по имени.

### Исправлено

- Маркировка рекламы оффера (`Offer::adMarking()`) раскодирует HTML-сущности, как остальные тексты оффера.
- Тексты магазинов и купонов (названия, описания, условия, инструкции, маркировка, виды, имена в справочниках)
  раскодируют HTML-сущности.
- HTML-сущности раскодируются только в тексте из CDATA и ровно один раз: у оффера `&amp;quot;` вне CDATA больше не
  превращается в `"`, а остаётся `&quot;`; сырой `&`, неизвестные сущности и ссылки на управляющие символы (`&#27;`)
  не меняются.
- Строчный `currencyId` (`rur`, `rub`) больше не отбрасывает оффер в `skipped()`.
- CLI `search`: если на странице нет офферов, но есть следующая страница, выводится подсказка `--page` вместо
  «Ничего не найдено.».
- Неверный адрес магазина больше не попадает в сообщение исключения и в `skipped()` (а с ним и код вебмастера из
  партнёрской ссылки).

### Планируется

- К 1.0 `Offer::affiliateLink()`, `Merchant::affiliateLink()`, `Coupon::affiliateLink()` и
  `affiliateLinkWithCode()` начнут возвращать `AffiliateLink` вместо строки. Уже сейчас берите `link()` /
  `linkWithCode()`; `(string) $link` даёт прежнюю строку.

## [0.1.0] — 2026-10-08

Первый выпуск. Неофициальная библиотека для API вебмастеров «Где Слон?».

### Добавлено

- **SDK** (`GdeSlon::create()`), PHP 8.1+, без зависимостей сверх расширений `curl`, `json`, `libxml`, `simplexml`,
  `xmlreader`; PSR-18 и PSR-16 — по желанию.
  - Категории товаров — дерево, путь от корня, загрузка частями, кэш с ETag.
  - Магазины вебмастера с тарифами, трафиком и партнёрскими ссылками (`shops.xml`), публичный каталог без токена.
  - Поиск товаров с партнёрскими ссылками (`search.xml`): фильтры по магазинам, категориям, артикулам, сортировка,
    страницы.
  - Заказы вебмастера (API по продажам) с фильтрами по периоду, статусу, типу, магазину и sub_id.
  - Приём postback о заказах с проверкой секрета (`PostbackReceiver`, query, form, JSON, XML, PSR-7).
  - Заявки на потерянные заказы: список, просмотр и создание с проверками до запроса и защитой от дублей.
  - Купоны и промокоды с маркировкой рекламы; токен в ссылках купонов скрыт в `var_dump`.
  - Исключения пакета реализуют `GdeSlonException`; токены и ключи не попадают в сообщения.
- **CLI** `vendor/bin/gdeslon` — все возможности SDK из командной строки: таблицы и стабильный JSON
  (`--format=json`), ключи только из окружения или `--env-file`, коды выхода 0–5, безопасная отправка заявок
  (подтверждение, `--dry-run`, коды «не повторять» и «уже есть»). См. [docs/cli.md](docs/cli.md).
- **MCP-сервер** `gdeslon mcp` для AI-агентов (Claude Desktop, Claude Code и др.): 11 инструментов чтения, MCP
  `2024-11-05`…`2025-11-25` и `2026-07-28`, маска секретов, страницы для длинных списков. См. [docs/mcp.md](docs/mcp.md).

[0.3.0]: https://github.com/webreboot/gdeslon-api/releases/tag/v0.3.0
[0.2.0]: https://github.com/webreboot/gdeslon-api/releases/tag/v0.2.0
[0.1.0]: https://github.com/webreboot/gdeslon-api/releases/tag/v0.1.0
