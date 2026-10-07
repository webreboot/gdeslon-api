# Магазины (рекламодатели)

Источник: https://gdeslon.ru/faq/20/ · Контекст: Catalog · Порт: `MerchantRepository`

`GET https://www.gdeslon.ru/api/users/shops.xml?api_token=<токен XML API>` (или `shops.json`).
По документации — «полная информация по рекламодателям с вашими диплинками»: ID в системе, логотип, домен и т. д.
Других параметров документация не описывает.

## Хост и авторизация (проверено 2026-10-07)

- Хост именно `www.gdeslon.ru`: на `gdeslon.ru` — 403 `{"detail":"Учетные данные не были предоставлены."}`,
  на `api.gdeslon.ru` — 403 `Affiliate token (_gs_at) is required parameter`.
- **Ошибки авторизации нет.** `shops.xml` без `api_token` и с неверным токеном (40 нулей) отвечает одинаково:
  200, тела побайтно совпадают — публичный список (см. «Без токена»). Отличить «токен не принят» можно только по
  содержимому: в публичном списке нет ни одного `<affiliate-link>`.
- `shops.json` и с токеном, и без — только `_id` и `name` (216 магазинов, 8 148 байт, `Content-Length`, слабый ETag,
  без сжатия). Порядок записей зависит от запроса. Все `<id>` из `shops.xml` (с токеном и без) есть в `shops.json`
  с тем же `name`; 13 магазинов есть только в `shops.json`.
  ```json
  [{"_id": 102001, "name": "stoloto.ru"}, {"_id": 117496, "name": "tbank.ru/gorod/afisha/"}]
  ```

## Что отдаёт `shops.xml` (проверено 2026-10-07)

| Запрос | Магазинов | Размер | `<affiliate-link>` |
|--------|-----------|--------|--------------------|
| с токеном | 142 — подмножество публичного списка | 1 395 457 байт | у 141 (нет у `101124` AliExpress RU&CIS) |
| без токена / с неверным токеном | 203 | 2 013 349 байт | ни у одного |

Для магазинов, которые есть в обоих ответах, все элементы, кроме `affiliate-link`, совпадают побайтно.
С токеном список, видимо, — магазины, доступные вебмастеру; смысл подмножества документацией не описан.

## Формат (проверено 2026-10-07, 142 магазина с токеном; 203 без токена — та же структура)

`<?xml version="1.0" encoding="UTF-8"?>`, без BOM, **без DOCTYPE и сущностей**, корень `<shops>` без атрибутов,
дальше только `<shop>`. Отступ 2 пробела, строки разделены `\n`; внутри CDATA текстов — `\r\n` из исходных текстов
(7 993 `\r` в документе). Сущности только `&amp;` (в `affiliate-link`) и `&quot;` (в атрибутах тарифов).

Порядок элементов `<shop>` всегда один: `id, name, short-description, description, url, conditions, is-green,
gs-commission-mark, country, kind, categories, logo-file-name, affiliate-link?, traffic-types, tariffs?, tagging_ads`.

| Элемент | Форма | Есть | Значения |
|---------|-------|------|----------|
| `id` | CDATA, цифры | 142 | 11751…118488, уникальны; = `_id` в `shops.json` |
| `name` | CDATA | 142 | обычно домен (`eduson.academy`), но бывает с путём (`payment.mts.ru/cyber`, `moskva.beeline.ru/shop/`) и не домен (`AliExpress WW`, `5ka.ru (Android & IOS)`, `kuper.ru (ex. sbermarket.ru)`); уникальны, до 38 символов |
| `short-description` | CDATA | 142 | непустое, до 143 символов, повторы бывают |
| `description` | CDATA, **markdown** (`**жирный**`, списки, `[текст](url)`) | 142 | непустое, до 6 648 символов, `\r\n` |
| `url` | CDATA | 142 | сайт магазина: `https://` (141), `http://` (1, `http://aliexpress.com/`); с `www.` — 26, со слэшем в конце — 117, с путём — 11 (`https://skyeng.ru/offers/cpa`); у 5 хост не совпадает с `name` (`gorzdrav.ru` → `https://gorzdrav.org/`) |
| `conditions` | CDATA, markdown | 142 | условия программы, акции, ограничения; непустое, до 5 564 символов |
| `is-green` | CDATA `true`/`false` | 142 | все `false`; смысл не документирован |
| `gs-commission-mark` | CDATA, свободный текст | 142 | сводка вознаграждения: `10,3%`, `5% - 16,49%`, `0.32-62.3%`, `3000 руб.`, `39,18руб; 1,61% - 6,71%`, `2180`; **пусто у 2** (`117999`, `118488`). Для разбора непригодно |
| `country` | текст | 142 | все `ru` |
| `kind` | CDATA | 142 | `Юридическое лицо` 77, `Физическое лицо` 57, `Индивидуальный предприниматель` 8 |
| `categories/category` | `<id>` (текст, цифры) + `<name>` (CDATA) | 142 | ровно одна у каждого. **Это классификатор магазинов, а не товарных категорий** `gdeslon-categories.json`: `50` «Обучение» (в товарных 50 нет), `3` «Цифровая и бытовая техника» (в товарных 3 — «Скульптуры и статуэтки»). С токеном 17 разных, без токена 19 (ещё `1` «Интернет-магазины», `52` «Развлечения и хобби») |
| `logo-file-name` | текст, URL | 142 | `https://cdn.gdeslon.ru/uploads/users/<id>/logos/big.{png,jpg,JPEG}?<число>` (путь содержит `id` магазина) |
| `affiliate-link` | текст, URL | 141 из 142 | `https://sf.gdeslon.ru/cf/<код вебмастера>?erid=<erid>&mid=<id магазина>`; `<код вебмастера>` — 10 hex, **один на все магазины — персональные данные вебмастера**; `mid` всегда = `id`; `erid` пустой у `82012` (`erid=&mid=82012`); `erid` совпадает с `erid` в `tagging_ads` (публичный) |
| `traffic-types/traffic-type` | `<name>` (CDATA) + `<allowed>` (`yes`/`no`) | 142 | у каждого ровно 23 типа в одном порядке (Контекстная реклама, … Cashback, Clickunder/Popunder, Doorway, … Мессенджеры); `yes` 2 239, `no` 1 027; у `106797` запрещены все 23 |
| `tariffs/tariff` | атрибуты + текст-ставка | 140 (380 тарифов) | `id` (цифры, или 32 hex у тарифов по категориям), `title` (364; нет у тарифов по категориям), `rate_type` `percent` 328 / `fixed` 52, `traffic_categories` (коды через запятую или `""` — 334 пустых), `category_name` (16, названия через `;`), `is_percent="true"` (16). Ставка — `\d+\.\d+` с точкой (`10.31`, `200.0`); валюта `fixed` не указана |
| `tariffs/tariff-category` | атрибуты + текст-ставка | 2 магазина (212) | `name` (категория товаров магазина), `category_id` (свой ID магазина, `1000`…), `is_percent="true"`; `106797` — 200 штук, `100880` — 12; всегда вместе с обычными `tariff` |
| `tariffs` | | нет у 2 | `101124`, `82012` (AliExpress) |
| `tagging_ads` | текст (не CDATA) | 142 | маркировка рекламы: `Реклама. Рекламодатель <юрлицо> ИНН <ИНН>, <сайт>. erid <erid>`; у `82012` — `<tagging_ads/>` |

Коды `traffic_categories` (25): `context_ad`, `context_ad_brand`, `popup_un_ad`, `doorway`, `banner_ad`, `teaser_ad`,
`motivation_traffic`, `social_networks`, `retarget`, `sm_targeting`, `promocodes`, `coupons`, `cashback`, `video`,
`toolbar`, `email_campaign`, `mobile_traffic`, `adult_traffic`, `showcases`, `sms`, `loyalty_programs`, `resell`,
`content_site`, `push_ads`, `messengers`. Соответствие кодов названиям `traffic-type` в API не описано.

Пример (сокращено, код вебмастера заменён):

```xml
<shop>
  <id><![CDATA[102317]]></id>
  <name><![CDATA[eduson.academy]]></name>
  <short-description><![CDATA[Онлайн-университет профессий]]></short-description>
  <description><![CDATA[**Академия Eduson** — это ведущий сервис онлайн-обучения…]]></description>
  <url><![CDATA[https://eduson.academy]]></url>
  <conditions><![CDATA[**С 01.10.2026 по 31.10.2026 действует…**]]></conditions>
  <is-green><![CDATA[false]]></is-green>
  <gs-commission-mark><![CDATA[5% - 16,49%]]></gs-commission-mark>
  <country>ru</country>
  <kind><![CDATA[Юридическое лицо]]></kind>
  <categories><category><id>50</id><name><![CDATA[Обучение]]></name></category></categories>
  <logo-file-name>https://cdn.gdeslon.ru/uploads/users/102317/logos/big.png?1791293173</logo-file-name>
  <affiliate-link>https://sf.gdeslon.ru/cf/0a1b2c3d4e?erid=Kra23eQ8w&amp;mid=102317</affiliate-link>
  <traffic-types>
    <traffic-type><name><![CDATA[Контекстная реклама]]></name><allowed>yes</allowed></traffic-type>
    <traffic-type><name><![CDATA[Контекстная реклама на бренд]]></name><allowed>no</allowed></traffic-type>
  </traffic-types>
  <tariffs>
    <tariff id="2144" title="Регистрация на живой вебинар" rate_type="fixed" traffic_categories="">200.0</tariff>
    <tariff id="1276" title="Оплаченный заказ … Cashback, Промокодная площадка" rate_type="percent" traffic_categories="cashback,coupons">10.31</tariff>
  </tariffs>
  <tagging_ads>Реклама. Рекламодатель ООО Эдюсон ИНН 7729779476, https://eduson.academy. erid Kra23eQ8w</tagging_ads>
</shop>
```

## HTTP (проверено 2026-10-07)

```
HTTP/1.1 200 OK
Server: nginx
Content-Type: application/xml; charset=utf-8
Transfer-Encoding: chunked
ETag: W/"dda383c21a620532594bb55e2bb9649f"
Cache-Control: max-age=0, private, must-revalidate
X-Request-Id: …
X-Runtime: 0.697873
X-Cached: MISS
```

- Приложение — Ruby on Rails за nginx (`X-Runtime`, `X-Request-Id`), не Express. `Content-Length`, `Last-Modified`,
  `Accept-Ranges` нет; ответ chunked.
- **ETag = `W/"` + первые 32 hex SHA-256 тела + `"`** (проверено сравнением хэша). Меняется при любом изменении тела.
- **Условные запросы работают:** `If-None-Match: W/"<etag>"` и `If-None-Match: "<etag>"` → `304 Not Modified`, без
  тела, с тем же ETag (и без токена — тоже). `If-Modified-Since` игнорируется → 200.
- **Два бэкенда — два ETag.** `www.gdeslon.ru` резолвится в 5.189.239.194 и 31.184.219.58; они отдают тот же набор
  магазинов **в разном порядке** (тела одной длины, ETag разные). ETag одного бэкенда на другом → 200 и всё тело.
  Порядок `<shop>` в ответе не стабилен (в 11:21 первым был `102317`, в 12:30 — `110632`).
- Данные меняются в течение дня: 1 395 454 байт в 11:21, 1 395 457 в 12:30.
- `X-Cached: MISS` — 0,85–1 с до первого байта; повтор вскоре после этого — `HIT`, 0,1 с. Весь ответ 0,2–1 с
  из сети разработчика.
- **Не сжимается**: на `Accept-Encoding: gzip` те же 1 395 457 байт без `Content-Encoding`.
- С сервера тестов: 1 395 457 байт за 1,3–4,1 с, из них DNS хоста 1,1–2,9 с; обрыва нет.
- PHP 8.1.34 и 8.4.26 (libxml 2.9.14): `XMLReader::XML()` по строке + `expand()` каждого `<shop>` разбирает все 142 за
  0,025 с, пик памяти процесса 1,8 МБ (тело 1,4 МБ в строке); из файла (`XMLReader::open`) — 0,5 МБ.

## Связь `id` магазина с другими разделами

- `id` = `merchant_id` оффера в `search.xml` (атрибут `offer merchant_id` и элемент `<merchant_id>`), = `mid`
  в партнёрских ссылках поиска и магазинов; параметр поиска `m=<id>` отдаёт офферы только этого магазина
  (`m=102317`, `m=82012`) — **проверено 2026-10-07**.
- `id` = `merchant_id` в заказах, postback и потерянных заказах — **по документации**: живьём проверить не на чем
  (у аккаунта разработчика за год нет ни заказов, ни заявок: оба списка — `[]`).

## Открытые вопросы

- что означает `is-green` и почему с токеном магазинов меньше, чем без токена;
- что получает токен без подключённых программ: пустой `<shops/>` или публичный список;
- валюта `fixed`-тарифов (по `gs-commission-mark` — рубли, в API не указана); смысл пустого `traffic_categories`
  (по смыслу — тариф для всех типов трафика).

## Уточнение (проверено 2026-10-07, живая проверка задачи магазинов)

- `tariff-category`: `name` бывает пустым — 1 из 212 (магазин 100880 wishmaster.me, `category_id="6"`); у остальных
  `name` вида «Категория 2». `category_id` — от 1 до 1019 (не только 1000+). `tariff-category` есть у 2 магазинов:
  100880 (12) и 106797 (200). Других пустых или нестандартных значений в 142 (с токеном) и 203 (без токена) магазинах нет.
