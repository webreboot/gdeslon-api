# CLI `gdeslon`

Консольная утилита поверх SDK: категории, магазины, поиск товаров, заказы, заявки на потерянные заказы и купоны.
Ставится вместе с пакетом:

```bash
composer require webreboot/gdeslon-api
vendor/bin/gdeslon help
```

или глобально: `composer global require webreboot/gdeslon-api`, затем `gdeslon help`.

## Ключи

Ключи берутся **только из окружения** (или файла `--env-file`) — не из аргументов, чтобы не попадать в историю
shell и список процессов.

| Переменная | Для чего | Где взять |
|---|---|---|
| `GDESLON_API_TOKEN` | `search`, `coupons`, `lost-orders`; `merchants` — магазины вебмастера со ссылками | https://gdeslon.ru/api_settings/xml |
| `GDESLON_USER_ID`, `GDESLON_API_KEY` | `orders` (задаются вместе) | https://gdeslon.ru/api_settings/orders |

`--env-file=PATH` — строки `KEY=VALUE` (`export `, комментарии `#`, кавычки `'…'`/`"…"` без подстановок, CRLF и BOM
допускаются). Читаются только `GDESLON_*`; переменная окружения процесса важнее файла.

Токен в выводе и сообщениях об ошибках заменяется на `***`.

## Вызов

```
gdeslon [опции] <команда> [подкоманда] [аргументы] [опции]
```

Общие опции (до или после команды):

| Опция | Что делает |
|---|---|
| `--format=table\|json` | формат вывода, по умолчанию `table` |
| `--env-file=PATH` | ключи из файла |
| `--cache-dir=DIR` | каталог кэша категорий и магазинов |
| `--no-cache` | без кэша |
| `--timeout=SEC` | таймаут запроса (по умолчанию 30, у `lost-orders submit` — 120) |
| `-h`, `--help` | справка по команде |
| `-V`, `--version` | версия |
| `--` | конец опций: дальше — только аргументы |

Значения — `--opt=value` или `--opt value` (во второй форме значение не может начинаться с «-»). Списки — через запятую и/или повтором: `--merchant=1,2 --merchant=3`.
Числа — положительные целые без ведущих нулей. Повтор обычной опции — ошибка.

Кэш по умолчанию: `$GDESLON_CACHE_DIR`, иначе `$XDG_CACHE_HOME/gdeslon-api`, `~/.cache/gdeslon-api` или
`%LOCALAPPDATA%\gdeslon-api\cache`. В кэше магазинов — партнёрские ссылки вебмастера: на общей машине каталог кэша
должен быть доступен только вам (не указывайте `--cache-dir` в общем `/tmp`).

Вывод — UTF-8. В консоли Windows включите UTF-8 (`chcp 65001` или Windows Terminal); в `docker compose exec`
кодировку задаёт терминал хоста. Управляющие символы из данных API в вывод не попадают (заменяются пробелом).

## Команды

### `categories [<ID>] [--depth=N]`

Дерево товарных категорий, ключ не нужен. С ID — категория, путь от корня и подкатегории; `--depth` — сколько уровней
подкатегорий показать.

### `merchants [list]`, `merchants show <ID>`, `merchants categories`

Магазины. С `GDESLON_API_TOKEN` — магазины вебмастера с партнёрскими ссылками, без него — публичный каталог. Фильтры
списка (складываются): `--search=TEXT` (название или домен), `--domain=HOST|URL`, `--category=ID` (ID из
`merchants categories`).

### `search [<запрос>…]`

Товары с партнёрскими ссылками. Слова запроса склеиваются пробелом; минус-слова — после `--` или в кавычках:
`gdeslon search -- iphone -pink`, `gdeslon search "iphone -pink"`.

| Опция | |
|---|---|
| `--merchant=ID,…`, `--exclude-merchant=ID,…` | только в магазинах / кроме магазинов |
| `--category=ID,…`, `--exclude-category=ID,…` | только в категориях / кроме категорий (ID из `categories`) |
| `--article=АРТИКУЛ,…` | артикулы |
| `--limit=1..100` | офферов на странице, по умолчанию 10 |
| `--page=N` | страница с 1; `page × limit ≤ 10 000` |
| `--sort=price\|partner-benefit\|newest` | порядок |
| `--parked-domain=URL` | припаркованный домен для ссылок |

### `orders`

Заказы вебмастера, нужны `GDESLON_USER_ID` и `GDESLON_API_KEY`. По умолчанию — созданные за 30 дней по московскому
«сегодня»; статус заказа обновляется в API на следующий день.

| Опция | |
|---|---|
| `--date-field=created\|transition\|last-updated\|confirmed\|accrued` | по какой дате период |
| `--until=ГГГГ-ММ-ДД`, `--days=1..3660` | конец и длина периода |
| `--merchant=ID` | один магазин |
| `--state=new,cancelled,pending,confirmed,paid` | статусы |
| `--type=product\|lead` | тип |
| `--sub-id=TEXT` | sub_id из ссылки |

### `lost-orders [list]`, `lost-orders show <ID>`

Заявки на потерянные заказы, нужен `GDESLON_API_TOKEN`. Фильтры списка: `--merchant=ID`, `--from=ГГГГ-ММ-ДД`,
`--until=ГГГГ-ММ-ДД`, `--claim-state=in-work|closed`, `--order-status=waiting|confirmed|declined`.

### `lost-orders submit`

⚠️ Создаёт **реальную** заявку у рекламодателя.

```bash
gdeslon lost-orders submit --merchant=2573 --order-number=GS123L --order-date=2026-09-24 \
    --order-total=554.34 --attachment=receipt.pdf [--description=TEXT]
```

1. До любого запроса проверяются дата (не старше 3 месяцев по Москве), сумма (до 2 знаков), чек (JPEG/PNG/PDF до
   10 МиБ).
2. Ищется заявка на тот же номер заказа этого магазина: есть — код 5, новая не отправляется
   (`--no-duplicate-check` — не искать).
3. Команда показывает сводку и просит ввести `yes`. `--yes` — без вопроса (для скриптов), `--dry-run` — только
   проверки, без отправки.
4. Перед отправкой поиск дублей повторяется (пока ждали ответа, заявка могла появиться). Если в списке заявок
   магазина есть неразобранные записи, проверить дубли нельзя — код 1, заявка не отправляется.
5. Запрос не повторяется. Код 4 — исход неизвестен (таймаут, обрыв, неразобранный ответ): **не повторяйте**, а через
   несколько минут проверьте `gdeslon lost-orders list --merchant=<ID>`.

Для скриптов:
- код 4 должен останавливать любые повторы — в том числе повтор задания очереди или cron;
- `echo yes | gdeslon lost-orders submit …` равносилен `--yes`: подтверждение читается из stdin;
- проверка дублей не защищает от **параллельных** запусков: два процесса могут одновременно не найти заявку и
  создать две. Отправляйте заявки последовательно (например, через `flock`);
- значение, начинающееся с «-», передавайте как `--description=-…`: в форме `--description -…` оно считается
  забытым значением (код 2), чтобы `--description --dry-run` не превратился в отправку.

### `coupons [list]`, `coupons show <ID>`, `coupons kinds`

Купоны и промокоды магазинов вебмастера, нужен `GDESLON_API_TOKEN`. Фильтры: `--merchant=ID,…`, `--kind=ID,…` (ID из
`coupons kinds`), `--active` — только действующие сейчас.

⚠️ API кладёт токен XML API в партнёрские ссылки купонов (`/ck/<токен>/<id>`). CLI выводит их с `/ck/***/`; настоящие
ссылки — с `--reveal-links` (в stderr — предупреждение). Не публикуйте такие ссылки как есть. Рядом со ссылкой
показывайте маркировку рекламы (`ad_marking`, erid).

### `mcp`

MCP-сервер для AI-агентов: читает JSON-RPC из stdin, отвечает в stdout, пока stdin открыт. Опции — общие
(`--env-file`, `--cache-dir`, `--no-cache`, `--timeout`) и `--reveal-links`; `--format` не используется. Коды выхода:
0 — stdin закрыт, 1 — клиент закрыл stdout, 2 — неверный вызов. Подключение, инструменты и протокол — в
[docs/mcp.md](mcp.md).

## Вывод

Данные — в stdout, сообщения и ошибки — в stderr. Таблицы — для людей, их вид может меняться. Для скриптов —
`--format=json`: корень всегда объект, ключи в snake_case, ключ присутствует всегда (нет значения — `null`).

- Деньги — `{"amount": "1999.99", "currency": "RUB"}` (строка, без float); сумма заявки — строка `"554.34"`.
- Моменты — ISO 8601 со смещением (`2026-10-07T13:00:00+03:00`), даты — `ГГГГ-ММ-ДД`.
- Перечисления: `state` заказа `new|cancelled|pending|confirmed|paid`, `type` `product|lead`, `claim_state`
  `in_work|closed`, `order_status` `waiting|confirmed|declined`, `rate_type` `percent|fixed`.
- `skipped` — записи ответа с битыми данными (они же — предупреждением в stderr).

| Команда | Корень JSON |
|---|---|
| `categories` | `{"categories": […]}`, с ID — ещё `"breadcrumbs": [{id, name}]` |
| `merchants` | `{"merchants": […], "skipped": […]}` |
| `merchants show` | `{"merchant": {…}}` |
| `merchants categories` | `{"categories": [{id, name}]}` |
| `search` | `{"query", "page", "limit", "total", "next_page", "offers": […], "skipped"}` |
| `orders` | `{"orders": […], "skipped": […]}` |
| `lost-orders` | `{"claims": […], "skipped": […]}` |
| `lost-orders show`, `submit` | `{"claim": {…}}`; `submit --dry-run` — `{"dry_run": true, "claim": {…}}` |
| `coupons` | `{"coupons": […], "kinds": […], "skipped": […]}` |
| `coupons show` | `{"coupon": {…}}` |
| `coupons kinds` | `{"kinds": [{id, name}]}` |

### Поля

Тип `?` — значение или `null`; `money` — `{amount: string, currency: string}`; `[T]` — список. Порядок ключей
стабилен. Схема закреплена тестом `tests/Unit/Interface/Normalizer/JsonSchemaTest.php`.

**Категория** (`categories[]`): `id` int, `parent_id` ?int, `name` string, `archived` bool, `path` [int] (ID от корня
до категории), `depth` int (1 — корневая), `offer_count` ?int (`null` — API не сообщает, это не ноль).

**Магазин** (`merchants[]`, `merchant`): `id` int, `name` string, `url` string, `domain` string, `short_description`
string, `description` string, `conditions` string, `logo_url` ?string, `country` ?string, `kind` ?string, `green` bool,
`commission_summary` ?string, `categories` [{`id` int, `name` ?string}], `affiliate_link` ?string (без токена —
`null`), `traffic_types` [{`name` string, `allowed` bool}], `tariffs` [{`id` string, `title` ?string, `rate_type`
`percent|fixed`, `rate` string, `traffic_categories` [string], `product_categories` [string]}], `category_tariffs`
[{`merchant_category_id` int, `name` ?string, `rate_type` `percent|fixed`, `rate` string}], `ad_marking` ?string.

**Поиск** (корень `search`): `query` ?string, `page` int, `limit` int, `total` ?int, `next_page` ?int (номер
следующей страницы для `--page`), `offers` [оффер], `skipped` [string].

**Оффер** (`offers[]`): `id` string, `merchant_id` int, `name` string, `price` money, `old_price` ?money, `charge`
?money (вознаграждение), `affiliate_link` string, `article` ?string, `category_id` ?int, `available` bool, `picture`,
`thumbnail`, `original_picture`, `description`, `vendor`, `model`, `product_url`, `ad_marking` — ?string.

**Заказ** (`orders[]`): `id` string, `merchant_id` int, `merchant_name` ?string, `state` string, `type` string,
`reward` money, `amount` ?money (у лида — `null`), `merchant_order_number` ?string, `sub_id` ?string, `affiliate_id`
?int, `item_count` ?int, `transition_at`, `created_at`, `last_updated_at`, `confirmed_at`, `accrued_at` — ?string
(момент), `keywords` ?string.

**Заявка** (`claims[]`, `claim`): `id` int, `order_number` string, `order_date` string (дата), `order_total` string,
`merchant_id` int, `merchant_name` ?string, `order_status` string, `claim_state` string, `description` ?string,
`attachment_url` ?string, `order_updated_at` ?string (момент).

**Новая заявка** (`submit --dry-run`, `claim`): `order_number` string, `order_date` string, `order_total` string,
`merchant_id` int, `description` ?string, `attachment` {`file_name` string, `type` `jpeg|png|pdf`, `size` int (байт)}.
Содержимое чека не выводится.

**Купон** (`coupons[]`, `coupon`): `id` int, `merchant_id` int, `merchant_name` ?string, `name` string, `description`
string, `instruction` ?string, `code` ?string (акция без промокода — `null`), `kind` {`id` ?int, `name` string},
`categories` [{`id` int, `name` ?string}], `starts_at` string, `ends_at` string (моменты), `affiliate_link` string,
`affiliate_link_with_code` ?string, `ad_marking` ?string. Вид купона (`kinds[]`) — {`id` ?int, `name` string}.

## Коды выхода

| Код | Когда |
|---|---|
| 0 | успех, в том числе пустой результат и `--dry-run` |
| 1 | ошибка: сеть, таймаут, HTTP, битый ответ, отказ API, «не найдено» в `show`, отказ от отправки заявки |
| 2 | неверный вызов: команда, опции, значения, даты, файл чека или `--env-file` |
| 3 | нет ключей для команды или API их не принял |
| 4 | заявка: исход неизвестен — **не повторять** |
| 5 | заявка на этот заказ уже есть, новая не отправлена |

Сбой вывода (полный диск, закрытый поток) — код 1, но у `lost-orders submit` после того, как исход известен, код
определяется исходом: 0 (создана), 4 или 5 — даже если отчёт записать не удалось.

## Совместимость

Стабильны (semver): имена команд, опций и переменных окружения, значения опций, коды выхода, ключи и типы JSON.
Новые команды, опции и поля добавляются в минорных версиях. Тексты таблиц и сообщений могут меняться.
