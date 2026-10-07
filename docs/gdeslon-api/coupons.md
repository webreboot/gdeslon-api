# Купоны и промокоды

Источник: https://gdeslon.ru/faq/18/ (перечитан 2026-10-07), статьи блога 2013 г. (`blog.gdeslon.ru` — 404, читались
из web.archive.org) · Контекст: Promo · Порт: `CouponFeed`

FAQ 18 описывает только Atom-ленту новых купонов (`https://beta.gdeslon.ru/api/coupons/feed.atom?_gs_at=ВАШ_API_ТОКЕН`)
и ссылается на блог: «выгрузка купонов в формате XML или CSV» (раздел кабинета `www.gdeslon.ru/coupons/export`, по
блогу — фильтры «категория рекламодателя», «рекламодатель», «тип купона», «формат»). Адрес выгрузки в документации не
приведён — найден живьём (ниже). Ещё есть JSON-ресурс DRF `/api/v1/coupons/` — в документации не упоминается.

**Три источника одних и тех же купонов (проверено 2026-10-07):**

| Источник | Авторизация | Купонов | Размер / время | Маркировка (erid) | Фильтры |
|----------|-------------|---------|----------------|-------------------|---------|
| `GET https://gdeslon.ru/api/coupons.xml?api_token=<токен>` | `api_token` в query | 916 | 1,09 МБ / 3,5–4 с | **да**: `erid` в ссылках и `tagging_ads` | `merchant_id`, `kind` |
| `GET https://gdeslon.ru/api/coupons.csv?api_token=<токен>` | то же | 916 | 785 КБ | да | (не проверялись; бэкенд тот же) |
| `GET https://gdeslon.ru/api/v1/coupons/` | `Authorization: Bearer <токен>` | 936 | 810 КБ / 5,8–6,9 с | **нет** | `merchant_id`, `kind` |
| `GET https://gdeslon.ru/api/coupons/feed.atom?_gs_at=<токен>` | `_gs_at` в query | 295 | 492 КБ / 0,2–2,3 с | нет | нет |

Токен везде один — токен XML API (https://gdeslon.ru/api_settings/xml).

> **Во всех ссылках купонов — сам токен XML API** (проверено 2026-10-07, побайтное совпадение):
> `http://xf.gdeslon.ru/ck/<токен XML API, 40 hex>/<id купона>?…` (Atom — хост `rf.gdeslon.ru`). Это не 10-символьный
> код вебмастера из ссылок поиска и магазинов (`/cm/`, `/cf/`). Опубликованная на сайте ссылка купона раскрывает токен,
> которым читаются купоны, магазины, поиск и **создаются заявки на потерянные заказы** (Bearer тот же). Ссылку сами не
> строим; в фикстурах токен — выдуманный 40 hex; в кэш и логи документ с купонами не попадает (см. аналитику задачи).

## Общее (проверено 2026-10-07)

- Бэкенд — Django REST Framework на `gdeslon.ru` (`Allow: GET, HEAD, OPTIONS`, `Vary: Accept-Language, Cookie`,
  `Content-Language: ru`), тот же, что у потерянных заказов. Ответ целиком, `Content-Length`, **без** `ETag`,
  `Last-Modified`, `Cache-Control`; `If-None-Match`/`If-Modified-Since` → 200 с телом. `Accept-Encoding: gzip` —
  без сжатия. Обрыва ~16 КБ нет (1,09 МБ одним соединением из сети разработчика).
- **Только действующие купоны.** Во всех 936 записях `start_at` ≤ момент запроса < `finish_at` (самый ранний конец —
  на следующий день, самое раннее начало — 2023-04-12). ID, которых нет в списке (`461305`, `461000`, `450000`, `400000`),
  в `GET /api/v1/coupons/{id}/` → 404. Отдаёт ли API истёкшие купоны в другой момент — не определить.
- **Даты без часового пояса — московское время**: `published` Atom (`2026-10-07T10:00:00+03:00`) совпадает с
  `start_at` JSON (`2026-10-07T10:00:00`) у всех 295 записей. Время в датах есть: `finish_at` чаще `23:59:59` (898 из
  936), бывает `00:00:00`, `23:59:00`, `09:59:59`, `11:06:00`; `start_at` — `00:00:00` (923), `10:00:00` и др.
- Только активные купоны магазинов, доступных вебмастеру: все 125 магазинов купонов есть в `shops.xml` с токеном.
- Пагинации нет нигде: `limit`, `offset`, `page`, `page_size` игнорируются (всегда весь список).

## XML-выгрузка: `GET https://gdeslon.ru/api/coupons.xml?api_token=<токен>` (проверено 2026-10-07)

То же на `https://www.gdeslon.ru/api/coupons.xml` (тело побайтно то же). Варианты `/api/coupons/`, `/api/coupons.json`,
`/api/coupons/{id}.xml` — 404 (HTML-страница сайта).

Авторизация **только** `api_token` в query: без него, с `_gs_at` или с `Authorization: Bearer` — 401; ошибки в XML:

```xml
<?xml version="1.0" encoding="utf-8"?>
<gdeslon-coupons><detail>Учетные данные не были предоставлены.</detail></gdeslon-coupons>
```

| Ситуация | Ответ (`application/xml; charset=utf-8`) |
|----------|-------|
| нет `api_token` (или токен не тем способом) | 401 `<detail>Учетные данные не были предоставлены.</detail>` |
| неверный токен (40 нулей) | 401 `<detail>Недопустимый токен.</detail>` |
| неверный фильтр | 400 `<gdeslon-coupons><merchant_id><list-item>“abc” является неверным значением.</list-item></merchant_id></gdeslon-coupons>` |

Фильтры (как у JSON, проверено 2026-10-07): `merchant_id` и `kind` — целые; повтор параметра — объединение
(`merchant_id=99157&merchant_id=118031` → купоны обоих); `0`, `99999999999`, ` 99157` → 400 «Выберите корректный
вариант. … нет среди допустимых значений.»; `abc`, пусто, `99157,118031` → 400 «“…” является неверным значением.».
`merchant`, `kind_id`, `category`, `category_id`, `coupon_category`, `merchant_category`, `limit` игнорируются (весь
список). Фильтр по категории из статьи блога в API не найден. Сочетание без совпадений → 200 с пустым `<coupons></coupons>`.

Формат: `<?xml version="1.0" encoding="utf-8"?>`, без BOM, **без DOCTYPE и CDATA**, весь документ почти в одну строку
(33 `\n`, 8 `\r` — внутри текстов); сущности только `&amp;` (в ссылках с `kc=`). Корень `<gdeslon-coupons>`, дальше
пять справочников в постоянном порядке:

```
gdeslon-coupons
  coupon-categories/coupon-category  {id, name}        категории купонов (только встречающиеся в выборке)
  categories/category                {id, name}        классификатор магазинов (как <categories> в shops.xml: 3, 33…)
  merchants/merchant                 {url, logo-file-name{regular,big,small}, categories/category{id}, name, id}
  kinds/kind                         {id, name}        виды купонов — всегда все 16 русских (не зависят от фильтра)
  coupons/coupon                     запись купона
```

Пустой результат (`kind=14&merchant_id=99157`): все справочники пустые (`<merchants></merchants>`), кроме `<kinds>`.

Порядок элементов `<coupon>` всегда один: `url-with-code, url, coupon-categories, description, start-at, name,
instruction, kind, merchant-id, id, finish-at, collaterals, code, tagging_ads`.

| Элемент | Форма | Значения (916 записей) |
|---------|-------|------------------------|
| `id` | цифры | 336004…461884, уникальны, = `id` JSON; порядок записей — по возрастанию `id` |
| `name` | текст | непустое, до 96 символов; пробелы по краям обрезаны (в JSON у 17 есть) |
| `description` | текст | непустое, до 658 символов, бывает `\n`/`\r\n`; обрезано по краям |
| `instruction` | текст | непустое, до 326 символов; у 888 из 936 (JSON) = `description` |
| `start-at`, `finish-at` | `YYYY-MM-DD HH:MM:SS` (через пробел), Москва | все действующие |
| `code` | текст или **пустой элемент** `<code></code>` | промокод у 230, пусто у 686 (купон без кода — акция); бывает кириллица (`ДляТебя`, `ЗДРАВСИТИ1`), `_`, цифры; до 22 символов; один код у многих купонов (`GLHOYO` — 49) |
| `kind` | **название** вида (не ID) | `скидка на заказ`, `SALE`, `подарок к заказу`, `деньги в подарок`, `бесплатная доставка`; ID — по справочнику `<kinds>` (имена там уникальны) |
| `merchant-id` | цифры | = `id` в `<merchants>` (есть у всех) и в `shops.xml` |
| `coupon-categories/coupon-category/id` | цифры | ровно одна у каждого; имя — в справочнике `<coupon-categories>` |
| `url` | `http://xf.gdeslon.ru/ck/<токен>/<id>?erid=<erid>` | всегда `http://`; `erid` у всех непустой, **свой у каждого купона** (916 разных; не `erid` магазина из `shops.xml`) |
| `url-with-code` | то же + `kc=<код>` перед `erid`: `…/<id>?kc=gdeslon&amp;erid=…` | без кода = `url`; код в `kc` URL-кодирован (`kc=%D0%94%D0%BB…`). «Ссылка с отображением купона при переходе» (блог 2013) |
| `collaterals/collateral` | `media-width`, `media-height`, `media-content-type` (`image/png`), `media-file-size` (байт), `media-file-name` (имя файла без пути), `name` (пустой), `id`, `coupon-id` | у 7 купонов (87 баннеров); у остальных `<collaterals></collaterals>` |
| `tagging_ads` | текст | маркировка рекламы у всех: `Реклама. Рекламодатель <юрлицо> ИНН <10 цифр>, <сайт>. erid <erid>` (868); у ИП/физлиц — `Реклама. ИП …`/`Реклама. <ФИО> ИНН <12 цифр> …` (48) — **персональные данные**; `erid` в тексте = `erid` в `url` у всех |

`merchants/merchant`: `url` — сайт магазина (`https://`), `name` = `name` магазина в JSON и `shops.xml`, `logo-file-name`
— **относительные** пути `/uploads/users/<id>/logos/regular.png` (`big`, `small`); файл отдают и
`https://gdeslon.ru/uploads/…`, и `https://cdn.gdeslon.ru/uploads/…` (проверено 2026-10-07).

Категории купонов (`coupon-category`) — **корневые товарные категории** `gdeslon-categories.json` (те же ID и имена у
20 из 22 встреченных: 1 «Подарки, сувениры, цветы», 2002 «Образование», 1111 «Скидки и акции»…); `255` «Одежда, обувь
и аксессуары» и `351` «Питание» в текущем файле категорий отсутствуют. Не путать с `<categories>` (классификатор
магазинов, как в `shops.xml`).

### Расхождения XML и JSON (проверено 2026-10-07)

- **В XML на 20 купонов меньше** (916 против 936): нет всех 8 купонов `AliExpress RU&CIS` (101124 — у него и в
  `shops.xml` нет партнёрской ссылки), 9 из 64 `ggsel.net`, 2 из 9 `webium.ru`, 1 `komus.ru`. По какому признаку
  исключены — не документировано (вероятно, нет `erid` купона: в XML `erid` есть у всех без исключения).
- Тексты в XML обрезаны по краям (`"gdeslon\n"` в JSON → `gdeslon`), остальное совпадает (имя, даты, код, магазин,
  вид, категория — у всех 916 общих).

## CSV-выгрузка: `GET https://gdeslon.ru/api/coupons.csv?api_token=<токен>` (проверено 2026-10-07, кратко)

`text/csv`, UTF-8 без BOM, `;`, строки `\r\n`, 916 записей. Колонки: `id;name;description;instruction;start_at;finish_at;
code;kind;merchant;logo;url;url-with-code;categories;tagging_ads`. **Ошибка выгрузки: в колонке `name` — описание, в
`description` — название** (у купона 336004). `merchant` — имя, `categories` — имя категории, `logo` — абсолютный
`http://gdeslon.ru/uploads/…`; в `url-with-code` `kc` идёт после `erid`. Для SDK не используется.

## JSON: `GET https://gdeslon.ru/api/v1/coupons/` (проверено 2026-10-07)

Корень `GET /api/v1/` → `{"lost-orders": …, "coupons": "http://gdeslon.ru/api/v1/coupons/", "kinds": "http://gdeslon.ru/api/v1/kinds/"}`.

- Авторизация — `Authorization: Bearer <токен XML API>`. Ошибки — **стандартный DRF `{"detail": …}`, без обёртки
  `{"errors": …}`** (в отличие от `/api/v1/lost-orders/`): нет заголовка — 401 `{"detail":"Недопустимый заголовок токена.
  Не предоставлены учетные данные."}`, неверный токен — 401 `{"detail":"Недопустимый токен."}`, схема `Token` — 401
  `{"detail":"Учетные данные не были предоставлены."}`, `_gs_at` в query вместо заголовка — 401 (как без заголовка).
- Только JSON: `Accept: text/html` → 406 `{"detail":"Невозможно удовлетворить \"Accept\" заголовок запроса."}`.
- Без слэша (`/api/v1/coupons`, `/api/v1/coupons/461847`) → 301.
- Ответ — **JSON-массив без обёртки**, все действующие купоны, по возрастанию `id`; пагинации нет (`limit=2`,
  `limit=100000`, `offset`, `page` — тот же массив 810 КБ).
- Фильтры `merchant_id`, `kind` — как у XML (400 `{"merchant_id":["“abc” является неверным значением."]}`,
  `{"kind":["Выберите корректный вариант. 999 нет среди допустимых значений."]}`); `kind=1&kind=14` → 877 купонов.
  `merchant`, `kind_id`, `category`, `category_id`, `categories`, `search`, `ordering`, `code`, `start_date`,
  `finish_at__gte`, `is_active`, `active` — игнорируются.
- Одна запись: `GET /api/v1/coupons/{id}/` → 200, объект = элемент списка; нет/не действует/`abc` → 404 `{"detail":"Не найдено."}`.

```json
{"id": 336004, "name": "Скидка 33% на первый заказ + бесплатная доставка по Москве и МО",
 "description": "… Минимальная сумма заказа - 3000 рублей.", "instruction": "…",
 "start_at": "2023-04-12T00:00:00", "finish_at": "2026-12-31T23:59:59", "code": "gdeslon",
 "url_with_code": "http://xf.gdeslon.ru/ck/<токен>/336004?sub_id=None&kc=gdeslon",
 "url": "http://xf.gdeslon.ru/ck/<токен>/336004?sub_id=None",
 "categories": [{"id": 351, "name": "Питание"}],
 "merchant": {"id": 99157, "name": "elementaree.ru", "manager_id": 103918},
 "kind": {"id": 1, "name": "скидка на заказ"}}
```

Все 12 полей есть у всех 936 записей. Типы: `id` int; `name`, `description`, `instruction`, `start_at`, `finish_at`,
`url`, `url_with_code` — строки; `code` — строка (243) или `null` (693), из строк 11 пустых `""` и одна `"gdeslon\n"`;
`categories` — массив ровно из одного `{id, name}`; `merchant` — `{id int, name str, manager_id int}`; `kind` — `{id, name}`.
Ссылки — без `erid`, с мусорным `sub_id=None` (Python `None` строкой); код в `kc` **не кодирован** (`kc=ДляТебя`,
`kc=gdeslon\n` с переводом строки внутри URL).

`GET /api/v1/kinds/` (Bearer; без — 401) → массив 26 видов `{id, name}`: 16 русских (как `<kinds>` XML: 1 «скидка на
заказ», 2 «подарок к заказу», 3 «бесплатная доставка», 4 «деньги в подарок», 5 «Black Friday», 6 «Новый год»,
7 «14 февраля», 8 «23 февраля», 9 «8 марта», 10 «Майские», 11 «Киберпонедельник», 12 «11/11», 13 «Скоро в школу»,
14 «SALE», 15 «Только в ГдеСлон», 16 «для мобильных приложений») и 10 английских 17–26 («Back to school», «Cyber
Monday», «Women\`s Day», «Valentine\`s Day», «Christmas Fest», «Free gift», «Discount on an order», «Free delivery»,
«Diwali fest», «Sale»; в XML их нет). Встречаются в купонах: 1 (632), 14 (245), 2 (40), 4 (13), 3 (6).

## Atom: `GET https://gdeslon.ru/api/coupons/feed.atom?_gs_at=<токен>` (проверено 2026-10-07)

- В документации `beta.gdeslon.ru` — редиректит на `gdeslon.ru`. Без токена — 401 `{"detail":"Учетные данные не были
  предоставлены."}`, неверный — 401 `{"detail":"Недопустимый токен."}` (JSON, хотя лента XML); Bearer не принимается.
- `application/xml`, Atom 1.0 (`xmlns="http://www.w3.org/2005/Atom"`, `xml:lang="ru-RU"`). **Токен в `<link rel="self">`
  ленты и в каждой записи.** Параметры (`merchant_id`) игнорируются.
- 295 записей — действующие купоны, начавшиеся за последние ~2 месяца (`start_at` ≥ 2026-08-10); `published` = начало
  акции (+03:00), `updated` — время правки с микросекундами. Остальное — только в HTML `<content type="html">`
  (сайт магазина, логотип, «Начало/Конец акции: ДД.ММ.ГГГГ», «Тип», описание, «Код купона: …», ссылки
  `http://rf.gdeslon.ru/ck/<токен>/<id>[?kc=…]`). Имени магазина, ID магазина в поле, категорий, `erid` — нет.
  Для SDK не используется: подмножество данных и разбор HTML.

## Открытые вопросы

- почему 20 купонов есть в JSON и нет в XML (признак исключения; можно ли их публиковать без `erid`);
- бывают ли в ответах истёкшие или ещё не начавшиеся купоны (на 2026-10-07 — нет);
- работает ли ссылка купона по `https://` (проверка — переход по ссылке, т. е. клик; живьём не делали);
- как получить ссылку купона без токена в пути (персональный код, парковка домена) — в API не найдено.
