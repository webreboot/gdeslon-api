<?php

declare(strict_types=1);

// Ссылки купонов не выводим: API кладёт в них токен (`/ck/<токен>/<id>`).
// Запуск: printf '%s' "$GDESLON_API_TOKEN" | ssh <сервер> 'cd gdeslon-api && docker compose exec -T php81 php scripts/live/coupons.php'

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
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
    printf('   время: %.2f с, память: %.1f МБ%s', microtime(true) - $started, memory_get_peak_usage() / 1048576, PHP_EOL);
}

echo 'PHP ', PHP_VERSION, PHP_EOL;
$gdeslon = GdeSlon::create(new Config(apiToken: $token, timeout: 60.0));

report('1. Все купоны', static function () use ($gdeslon): void {
    $coupons = $gdeslon->coupons();
    $now = new DateTimeImmutable();
    printf('   купонов: %d, действуют сейчас: %d, с кодом: %d, с маркировкой: %d, пропущено: %d, видов: %d%s',
        count($coupons),
        count($coupons->activeAt($now)),
        count($coupons->filter(static fn ($c): bool => $c->hasCode())),
        count($coupons->filter(static fn ($c): bool => $c->adMarking() !== null)),
        count($coupons->skipped()),
        count($coupons->kinds()),
        PHP_EOL,
    );
    foreach (array_slice($coupons->skipped(), 0, 3) as $reason) {
        echo '   пропуск: ', $reason, PHP_EOL;
    }
});

report('2. Магазин 99157, вид 1', static function () use ($gdeslon): void {
    $coupons = $gdeslon->coupons(new CouponCriteria(merchants: [99157], kinds: [1]));
    printf('   купонов: %d, магазины: %s%s', count($coupons), implode(',', array_unique(array_map(static fn ($c): int => $c->merchantId()->value(), $coupons->all()))), PHP_EOL);
});

report('3. Неподключённый магазин (ожидается CouponCriteriaRejectedException)', static function () use ($gdeslon): void {
    $gdeslon->coupons(CouponCriteria::forMerchant(23707));
});

report('4. Неверный токен (ожидается AuthenticationException)', static function (): void {
    GdeSlon::create(new Config(apiToken: 'not-a-real-token-0000'))->coupons();
});
