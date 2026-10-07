<?php

declare(strict_types=1);

// Партнёрские ссылки выводим только хостом: в них код вебмастера.
// Запуск: printf '%s' "$GDESLON_API_TOKEN" | ssh <сервер> 'cd gdeslon-api && docker compose exec -T php81 php scripts/live/search.php'

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\GdeSlon;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$token = trim((string) stream_get_contents(STDIN));
if ($token === '') {
    fwrite(STDERR, "Передайте токен XML API через STDIN\n");
    exit(2);
}

function report(string $title, callable $check): void
{
    echo PHP_EOL, '== ', $title, PHP_EOL;
    $started = microtime(true);
    try {
        $check();
    } catch (GdeSlonException $e) {
        echo '   исключение ', $e::class, ': ', $e->getMessage(), PHP_EOL;
    }
    printf('   время: %.2f с%s', microtime(true) - $started, PHP_EOL);
}

echo 'PHP ', PHP_VERSION, PHP_EOL;
$gdeslon = GdeSlon::create(new Config(apiToken: $token, timeout: 15.0));

report('1. Поиск по умолчанию, limit 5', static function () use ($gdeslon): void {
    $result = $gdeslon->search(new SearchCriteria(limit: 5));
    $first = $result->offers()[0] ?? null;
    printf('   офферов: %d, total: %s, пропущено: %d, есть следующая: %s%s', count($result), var_export($result->total(), true),
        count($result->skipped()), $result->hasNextPage() ? 'да' : 'нет', PHP_EOL);
    if ($first !== null) {
        printf('   первый: %s — %s, магазин %d, ссылка на %s%s', $first->name(), $first->price(), $first->merchantId()->value(),
            parse_url($first->affiliateLink(), PHP_URL_HOST), PHP_EOL);
    }
});

report('2. Фильтр по двум магазинам, сортировка по цене', static function () use ($gdeslon): void {
    $result = $gdeslon->search(new SearchCriteria(merchants: [107054, 111211], limit: 5, sort: OfferSort::Price));
    printf('   офферов: %d, total: %s, магазины: %s%s', count($result), var_export($result->total(), true),
        implode(',', array_unique(array_map(static fn ($o): int => $o->merchantId()->value(), $result->offers()))), PHP_EOL);
    $next = $result->nextPage();
    printf('   следующая страница: %s%s', $next === null ? 'нет' : (string) $next->page(), PHP_EOL);
});

report('3. Запрос с минус-словом', static function () use ($gdeslon): void {
    $result = $gdeslon->search(new SearchCriteria(query: 'платье -детское', limit: 3));
    printf('   офферов: %d, total: %s%s', count($result), var_export($result->total(), true), PHP_EOL);
});

report('4. Неверный токен (ожидается AuthenticationException)', static function (): void {
    GdeSlon::create(new Config(apiToken: 'not-a-real-token'))->search('x');
});

report('5. limit 100 (из сети с обрывом ~16 КБ ожидается TimeoutException с подсказкой)', static function () use ($gdeslon): void {
    $result = $gdeslon->search(new SearchCriteria(limit: 100));
    printf('   загрузилось: %d офферов%s', count($result), PHP_EOL);
});
