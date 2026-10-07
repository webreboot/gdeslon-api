# MCP-сервер `gdeslon mcp`

Локальный [MCP](https://modelcontextprotocol.io/)-сервер для AI-агентов (Claude Desktop, Claude Code и других
клиентов): категории, магазины, поиск товаров с партнёрскими ссылками, заказы, заявки на потерянные заказы и купоны
«Где Слон?». Неофициальный проект, только чтение. Транспорт — stdio, ответы — те же объекты JSON, что у
`gdeslon --format=json` ([docs/cli.md](cli.md), раздел «Поля»).

## Запуск

Сервер запускает клиент, а не вы:

```
php vendor/bin/gdeslon mcp [--env-file=PATH] [--cache-dir=DIR | --no-cache] [--timeout=SEC] [--reveal-links]
```

| Опция | |
|---|---|
| `--env-file=PATH` | ключи `GDESLON_*` из файла (окружение процесса важнее) |
| `--cache-dir=DIR`, `--no-cache` | кэш категорий и магазинов, как у CLI |
| `--timeout=SEC` | таймаут запроса к API, по умолчанию 30 |
| `--reveal-links` | настоящие ссылки купонов с токеном XML API (см. «Безопасность») |

Сервер читает JSON-RPC из stdin по одному сообщению на строку и отвечает в stdout. Сообщение о запуске и ошибки
сервера пишутся в stderr. Когда stdin закрывается, сервер завершается с кодом 0. Неверные опции дают код 2 до чтения
stdin.

## Ключи

| Переменная | Инструменты |
|---|---|
| `GDESLON_API_TOKEN` — https://gdeslon.ru/api_settings/xml | `search_offers`, `list_lost_order_claims`, `get_lost_order_claim`, `list_coupons`, `get_coupon`, `list_coupon_kinds`; в `list_merchants` и `get_merchant` — магазины вебмастера с партнёрскими ссылками |
| `GDESLON_USER_ID`, `GDESLON_API_KEY` — https://gdeslon.ru/api_settings/orders | `list_orders` |

Без ключей сервер тоже запускается: работают `get_categories` и публичный каталог магазинов. Остальные инструменты
отвечают ошибкой `[access]` с именем нужной переменной.

Держите ключи в файле с правами `0600` и передавайте его через `--env-file`, а не в `env` конфигурации клиента:
`claude_desktop_config.json` и `.mcp.json` хранят значения открытым текстом, а `.mcp.json` легко попадает в git.

## Подключение

**Claude Code:**

```bash
claude mcp add --transport stdio gdeslon -- php /path/to/project/vendor/bin/gdeslon mcp --env-file=/home/me/.config/gdeslon.env
```

**Claude Desktop** (`claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "gdeslon": {
      "command": "php",
      "args": ["/path/to/project/vendor/bin/gdeslon", "mcp", "--env-file=/home/me/.config/gdeslon.env"]
    }
  }
}
```

**Windows:** `"command": "php"` (или полный путь к `php.exe`), путь к скрипту — `C:\\path\\vendor\\bin\\gdeslon`.

**Docker:** `docker compose exec -T <сервис> php vendor/bin/gdeslon mcp`. Ключ `-T` обязателен: TTY испортит поток
протокола.

## Протокол

Сервер понимает две эпохи MCP:
- **legacy** — рукопожатие `initialize`, версии `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`. На неизвестную
  версию сервер отвечает `2025-11-25`;
- **modern** — `2026-07-28`, без рукопожатия: версия и возможности клиента приходят в `params._meta` каждого
  запроса, есть `server/discover`. Неподдерживаемая версия в `_meta` даёт ошибку `-32022`, где в `data.supported` —
  версии, которые принимаются в `_meta`; legacy-версии работают только через `initialize`.

Методы: `initialize`, `ping`, `server/discover`, `tools/list`, `tools/call`. Уведомления (`notifications/*`) ответа
не получают. Батчи JSON-RPC принимаются. Ресурсов, промптов и уведомлений сервера нет.

Запросы выполняются по одному. `notifications/cancelled` не прерывает начатый вызов: заказы грузятся 4–6 с, купоны —
3–4 с.

Успешный `tools/call` возвращает JSON дважды: строкой в `content[0].text` и объектом в `structuredContent`, по
`outputSchema` инструмента. Клиентам версий до `2025-06-18` отдаётся только текст.

## Инструменты

Все инструменты только читают данные (`readOnlyHint: true`). Аргументы — JSON-значения строгих типов: строка `"5"`
вместо числа — ошибка, `null` означает «не задан». Перечисления — в snake_case, даты — `ГГГГ-ММ-ДД`.

Списки отдаются страницами. Аргументы `limit` и `offset`, в ответе — `total` (после фильтров), `limit`, `offset` и
`next_offset` (`null` — страница последняя). Следующая страница — `offset = next_offset`. API отдаёт купоны и магазины
целиком, поэтому каждая страница загружает их заново, если не помог кэш.

| Инструмент | Аргументы | Результат |
|---|---|---|
| `get_categories` | `category_id`, `depth` 0–5, `name_contains`, `limit` (100, до 500), `offset` | `{categories, breadcrumbs, total, limit, offset, next_offset}`. Без аргументов — корневые категории; с `category_id` — категория, путь и подкатегории на `depth` уровней (по умолчанию 1) |
| `list_merchants` | `search`, `domain`, `merchant_category_id`, `limit` (20, до 100), `offset` | `{merchants, skipped, total, …}`, короткая форма магазина: `id, name, domain, url, commission_summary, categories, affiliate_link, ad_marking` |
| `get_merchant` | `merchant_id` | `{merchant}` — полная форма |
| `list_merchant_categories` | — | `{categories: [{id, name}]}` |
| `search_offers` | `query`, `merchant_ids`, `exclude_merchant_ids`, `category_ids`, `exclude_category_ids`, `articles`, `limit` (10, до 100), `page`, `sort` (`price`, `partner_benefit`, `newest`), `parked_domain` | `{query, page, limit, total, next_page, offers, skipped}` |
| `list_orders` | `date_field` (`created`, `transition`, `last_updated`, `confirmed`, `accrued`), `until`, `days` (30, до 3660), `merchant_id`, `states` (`new`, `cancelled`, `pending`, `confirmed`, `paid`), `type` (`product`, `lead`), `sub_id`, `limit` (50, до 200), `offset` | `{orders, skipped, total, …}` |
| `list_lost_order_claims` | `merchant_id`, `from`, `until`, `claim_state` (`in_work`, `closed`), `order_status` (`waiting`, `confirmed`, `declined`), `limit` (50, до 200), `offset` | `{claims, skipped, total, …}` |
| `get_lost_order_claim` | `claim_id` | `{claim}` |
| `list_coupons` | `merchant_ids`, `kind_ids`, `active_only`, `limit` (20, до 100), `offset` | `{coupons, kinds, skipped, total, …}` |
| `get_coupon` | `coupon_id` | `{coupon}` |
| `list_coupon_kinds` | — | `{kinds: [{id, name}]}` |

Создавать заявки на потерянные заказы через MCP нельзя. Это реальная заявка у рекламодателя, её отправляют командой
`gdeslon lost-orders submit` в терминале ([docs/cli.md](cli.md)).

### Ошибки

Ошибка в самом вызове (неизвестный инструмент, `arguments` не объект, битый JSON-RPC) — это ошибка JSON-RPC: коды
`-32700`, `-32600`, `-32601`, `-32602`, `-32603`, `-32022`. Ошибка выполнения — результат с `isError: true`, его текст
начинается со стабильного тега:

| Тег | Когда |
|---|---|
| `[invalid_arguments]` | неверные или лишние аргументы, ограничения до запроса (все проблемы — одним сообщением) |
| `[access]` | нет ключа в окружении сервера или API его не принял |
| `[not_found]` | записи с таким ID нет |
| `[failed]` | сеть, таймаут, ошибка HTTP, битый ответ, отказ API (`поле: сообщение`) |
| `[too_large]` | ответ больше ~400 КБ — уменьшите `limit` или сузьте фильтры |
| `[internal]` | непредвиденная ошибка сервера |

## Безопасность

- В stdout пишется только протокол. Значения токена и ключа API по продажам маскируются (`***`) во всех ответах и в
  stderr.
- API кладёт токен XML API в каждую партнёрскую ссылку купона (`/ck/<токен>/<id>`). По умолчанию сервер заменяет его
  на `***`. С `--reveal-links` настоящие ссылки попадают в контекст модели, историю диалога и логи клиента и
  провайдера, а этим токеном читаются ваши данные и создаются заявки. Включайте флаг осознанно; агент сам включить
  его не может. С флагом `instructions` и описания купонных инструментов предупреждают модель, что ссылки
  публиковать нельзя.
- Тексты магазинов и купонов пишут рекламодатели. Для модели это недоверенные данные, не инструкции; сервер говорит
  об этом в `instructions`.
- Клиенты ограничивают размер вывода инструментов. Например, Claude Code сохраняет ответ длиннее 50 000 символов в
  файл. Значения `limit` по умолчанию держат ответ в пределах ~30 000 символов.

## Совместимость

Стабильны (semver): имена инструментов, аргументов и значений перечислений, ключи и типы `structuredContent`, теги
ошибок, опции `gdeslon mcp`, поддерживаемые версии протокола. Новые инструменты, аргументы и поля добавляются в
минорных версиях. Тексты описаний, `instructions` и сообщений могут меняться.
