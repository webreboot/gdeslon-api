# Поиск товаров (XML API)

Источник: https://gdeslon.ru/faq/20/ · Контекст: Catalog · Порт: `ProductCatalog`

`GET https://api.gdeslon.ru/api/search.xml` — товарные предложения (офферы) всех подключённых магазинов
с партнёрскими ссылками вебмастера. Ответ — XML «в формате YML» (https://yandex.ru/support/marketplace/assortment/auto/yml.html),
но структура своя (см. «Ответ»).

## Параметры query

| Параметр | Смысл | Факт |
|----------|-------|------|
| `_gs_at` | токен XML API | **обязателен**. Без него — 403 `Affiliate token (_gs_at) is required parameter`; несуществующий — 403 `This affiliate token does not exists`; **неверного формата — 404** с эхом токена в теле (все `text/html`, проверено 2026-10-07; см. «Ошибки») |
| `q` | ключевые слова: название, описание, параметры товара | `AND`, минус-слова работают: `iphone` 55 559 = `iphone AND pink` 953 + `iphone -pink` 54 606; кириллица в UTF-8 (`q=платье` → 8 384) — проверено 2026-10-07. `OR` и скобки — по документации |
| `m` | ID магазинов, в чьих товарах искать | несколько — **через запятую** (`m=107054,111211` и `m=107054%2C111211` → 1 096 + 4 226 = 5 322); **повтор параметра `m=…&m=…` → 500** (проверено 2026-10-07) |
| `no_m` | ID магазинов, исключённых из поиска | через запятую (`%2C` тоже), оба магазина исключаются (проверено 2026-10-07) |
| `tid` | ID категорий для поиска | через запятую: `tid=26` 61 + `tid=349` 151 = `tid=26%2C349` 212 (при `m=107054`). **Только сама категория, без дочерних**: `tid=1` (родитель 26) при `m=107054` → пусто (проверено 2026-10-07) |
| `no_tid` | ID категорий, исключённых из поиска | `m=107054&no_tid=26` → 1 096 − 61 = 1 035 (проверено 2026-10-07) |
| `articles` | артикулы товаров | через запятую; ищет по всем магазинам: `articles=578237,434967` → 5 офферов 3 магазинов (проверено 2026-10-07) |
| `l` | количество товаров на странице | документация: «не более 100». **Без `l` и при `l=0` — 10** (не 5, как в FAQ). `l=101` сервер принимает (смещение `p=2` = 101). Проверено 2026-10-07 |
| `p` | номер страницы | с 1; `p=0` — как `p=1`. **Глубже 10 000-го товара нельзя**: `l=10&p=1000` → 200, `l=10&p=1001` и `l=5&p=200001` → 500 `Something broken!` (окно `p × l ≤ 10 000`, проверено 2026-10-07) |
| `order` | сортировка | `price`, `partner_benefit`, `newest`. `order=price` при `m=107054` — первые 5 по **убыванию** цены (2627 → 1999.99; проверено 2026-10-07, вся ли выдача по убыванию — не проверено). `partner_benefit`, `newest`, неизвестное значение — по документации/не проверено |
| `parked_domain_name` | припаркованный домен для партнёрских ссылок | `parked_domain_name=http://example.com` → `url` оффера = `http://example.com/cm/<код вебмастера>/?mid=…` (схема и хост заменяются значением параметра; проверено 2026-10-07) |

Порядок выдачи стабилен: `l=10` в 14:20 и 16:39 — те же байты, кроме `yml_catalog date` (проверено 2026-10-07).

## Ответ (проверено 2026-10-07, 65 разных офферов 11 магазинов из 30 ответов)

Весь документ — **одна строка**, `<?xml version="1.0" encoding="UTF-8"?>`, без BOM, сразу за декларацией
`<!DOCTYPE yml_catalog SYSTEM "shops.dtd">` (внешний DTD, внутреннего подмножества нет). Корень `yml_catalog`,
**без `<shop>` и `<categories>`**:

```
yml_catalog[date]
  name            «Где Слон?»
  company         «Партнерские Сети Он-лайн»
  url             http://api.gdeslon.ru
  currencies/currency[id="RUR" rate="1" plus="0"]
  info/documents_number
  offers/offer*   или <offers/>, если ничего не найдено
```

- `yml_catalog date="YYYY-MM-DD HH:MM:SS"` — время **московское** (UTC+3) без зоны: `16:39:56` при `Date: 13:39:56 GMT`.
- `documents_number` — **точное число найденных** при фильтрах (`m`, `tid`, `q`: суммы сходятся, см. выше), но
  **1000000** без фильтров и **при пустой выдаче** (`q=<бессмыслица>`, `tid=1&m=107054` → `<offers/>` и 1000000).
  1000000 = «неизвестно / не меньше миллиона», а не число.
- Пустая выдача — `<offers/>`, 387 байт, 200.

### `offer`

Атрибуты (всегда все, в этом порядке): `gs_product_key available merchant_id id article gs_category_id`.
Элементы (всегда в этом порядке): `price, oldprice, charge, merchant_id, currencyId, picture, thumbnail, name,
description, vendor, model, original_picture, tagging_ads/info, url, destination-url-do-not-send-traffic?`.
**Нет** `param`, `categoryId`, `available`-элемента, нескольких `picture`.

| Поле | Форма | Факты |
|------|-------|-------|
| `@id` | 18–20 цифр | у всех 65 оканчивается нулями (`14367733380732236000`) — похоже на потерю точности double в Node.js; **больше `PHP_INT_MAX`** — только строкой; уникальность не гарантирована |
| `@gs_product_key` | строка | всегда `""` |
| `@available` | `true`/`false` | `false` у 2 из 65 |
| `@merchant_id` | цифры | = `<merchant_id>` у всех; = `id` магазина в shops.xml |
| `@article` | строка | цифры (6–16 знаков) или латиница с цифрами (`p67ceos`); пустых нет; **не уникален** между магазинами |
| `@gs_category_id` | цифры или `""` | товарная категория ([categories.md](categories.md)); **пусто у 1** из 65 |
| `price` | `\d+` или `\d+\.\d{1,2}` | точка, без пробелов; **`0` у 3** (110632); до 193 654.05 |
| `oldprice` | как `price` или `<oldprice/>` | пусто у 13; `oldprice > price` 47, `= price` 5, `< price` 0 |
| `charge` | как `price` | вознаграждение вебмастера **в деньгах** валюты оффера: `3.64` при `price` 100 (магазин 107054, тариф ~3,64 %); **`0` у 7 магазинов из 11** (смысл нуля не описан) |
| `currencyId` | `RUR` | у всех (код `RUR`, не ISO `RUB`) |
| `picture` | URL | ровно один; `https://imgng.gdeslon.ru/commodities/<n>/pictures/<hash>/big.jpg` |
| `thumbnail` | URL | `…/small.jpg` того же товара |
| `original_picture` | URL | картинка с сайта магазина (`https://cdn.amwine.ru/…`, `aliexpress-media.com`) |
| `name` | CDATA | непустое; **HTML-сущности внутри CDATA как текст**: `Джеггинсы &quot;Анабель&quot;`, рядом сырой `&` (`Cica & Ceramide`) |
| `description` | CDATA | пусто у 57 из 65 (`<![CDATA[]]>`); до ~2 000 символов; встречаются markdown (`**Dantex**`, списки), `\n` (LF, без `\r`), отступы и пробелы по краям, `&nbsp;` как текст, склеенные абзацы (HTML-теги вырезаны) |
| `vendor` | CDATA | пусто у 14 |
| `model` | CDATA | пусто у 62 |
| `tagging_ads/info` | CDATA | маркировка: `Реклама. Рекламодатель <юрлицо> ИНН <ИНН> , <сайт> erid <erid>`; у AliExpress (82012) — `Реклама. Рекламодатель http://aliexpress.com/.`. Бывает **ИП с ФИО** — персональные данные рекламодателя |
| `url` | URL, `&amp;` | **партнёрская ссылка**: `https://af.gdeslon.ru/cm/<код вебмастера>/?mid=<merchant_id>&goto=<URL товара, urlencoded>&erid=<erid>`; у 82012 без `erid`, `goto` — `https://s.click.aliexpress.com/s/<длинный токен>`. `<код вебмастера>` — те же 10 hex, что в `/cf/` магазинов (**персональные данные**). С `parked_domain_name` — `<домен>/cm/…` |
| `destination-url-do-not-send-traffic` | CDATA, URL | прямая ссылка на товар — **не для трафика**; нет у 82012 (10 из 10) |

Сущности в документе: `&amp;` (в `url`), остальное — внутри CDATA как текст. Других данных вебмастера (sub_id, домен
парковки без параметра) в ответе нет.

## Ошибки (проверено 2026-10-07)

| Ситуация | Ответ |
|----------|-------|
| без `_gs_at` | 403 `text/html`, `Affiliate token (_gs_at) is required parameter` |
| несуществующий `_gs_at` (правильного формата) | 403 `text/html; charset=utf-8`, 36 байт, `This affiliate token does not exists` |
| `_gs_at` неверного формата | **404** `text/html`, ~115 байт, `There have been validation errors: [ { param: '_gs_at', … value: '<токен>' } ]` — **значение токена в теле** |
| `p × l > 10 000`; повтор параметра (`m=1&m=2`) | 500 `text/html; charset=utf-8`, 17 байт, `Something broken!` |
| ничего не найдено | 200, `<offers/>`, `documents_number` 1000000 |

## HTTP и размер ответа (проверено 2026-10-07)

Отдаёт Express за nginx (`api.gdeslon.ru`, Hetzner). Заголовки 200:

```
HTTP/1.1 200 OK
Content-Type: text/xml; charset=utf-8
Transfer-Encoding: chunked
Connection: keep-alive
X-Powered-By: Express
```

- `Content-Length`, `ETag`, `Last-Modified`, `Accept-Ranges`, `Cache-Control` **нет**; ответ потоковый (chunked).
  Условные запросы невозможны.
- **Не сжимается**: на `Accept-Encoding: gzip`, `br` и `--compressed` (`deflate, gzip, br, zstd`) — без
  `Content-Encoding`, те же байты.
- **`Range` игнорируется**: `Range: bytes=0-16383` (с `gzip` и с `identity`, с `Connection: close` и без) → `200`
  и весь документ. На `Connection: close` сервер отвечает `Connection: close`.
- Размер: `l=5` — 8 605 байт, `l=8` — 14 999, `l=10` — 19 537 (~1,9 КБ на оффер; с длинными описаниями больше);
  время 0,2–0,6 с до первого байта.
- **Обрыв из сети разработчика**: `l=100` (~190 КБ) — и без `q`, и с `q=платье` зависает на 16 011 байтах (7 из 7
  локально, 1 из 1 с сервера тестов), как категории ([categories.md](categories.md#обрыв-загрузки-проверено-2026-10-07)).
  **`l=10` тоже может зависнуть**: `l=10&p=11` (косметика с описаниями) — на 16 019 байтах; безопасного `l` нет,
  граница — ~16 КБ тела.
- Повторный одинаковый запрос даёт те же байты, кроме атрибута `yml_catalog date` (длина та же).

## Разбор в PHP (проверено 2026-10-07, PHP 8.1.34 и 8.4.26, libxml 2.9.14)

`XMLReader::XML($body, null, LIBXML_NONET)` на настоящем ответе: DTD `shops.dtd` **не загружается** (сеть не
используется, `libxml_get_errors()` пуст, warning'ов нет, ~1 мс); узел `DOC_TYPE` с `name` `yml_catalog`,
`readOuterXml()` = `<!DOCTYPE yml_catalog SYSTEM "shops.dtd">` ровно; `&amp;` в `url` раскрывается; CDATA отдаётся
как есть (`&quot;` остаётся текстом). Без `LIBXML_NOENT`/`LIBXML_DTDLOAD`: внешняя сущность `file:///etc/passwd` и
сущность внутреннего подмножества не подставляются (узел `ENTITY_REF`, пустой текст), внешний DTD по сети и
параметрическая сущность не загружаются (0 мс), «billion laughs» → ошибка `Detected an entity reference loop`.
`readOuterXml()` узла `DOC_TYPE` показывает внутреннее подмножество (`[<!ENTITY …>]`) — по нему можно отличить
настоящий DOCTYPE от подложенного. Обрезанный документ → ошибка libxml в `libxml_get_errors()`.

## Открытые вопросы

- `order=partner_benefit`, `order=newest`, неизвестный `order`; `order=price` — по убыванию ли вся выдача;
- `q` с `OR` и скобками; `p` за пределами выдачи при фильтре (`documents_number` меньше `p × l`);
- смысл `charge = 0` (фиксированный тариф? нет тарифа?) и `price = 0`;
- может ли `picture` быть несколько, придут ли `param`/`categoryId` из YML.

## Ошибки токена (проверено 2026-10-07, живая проверка задачи поиска)

- Токен **неверного формата** (например, с `-`): **404** `text/html`, ~115 байт:
  `There have been validation errors: [ { param: '_gs_at', msg: 'Invalid value', value: '<переданный токен>' } ]` —
  **значение токена эхом в теле**. Фрагмент тела в исключениях обязан его маскировать.
- Токен правильного формата, но несуществующий (`x`, 40 hex): **403** `This affiliate token does not exists` (36 байт).
