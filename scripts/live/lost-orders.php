<?php

declare(strict_types=1);

// Ручная живая проверка заявок на потерянные заказы — ТОЛЬКО ЧТЕНИЕ (GET). submitLostOrderClaim() здесь не вызывается и
// вызываться не должен: POST создаёт реальную заявку у рекламодателя. Токен XML API читается из STDIN и не выводится.
// Запуск: printf '%s' "$GDESLON_API_TOKEN" | ssh <сервер> 'cd gdeslon-api && docker compose exec -T php81 php scripts/live/lost-orders.php'

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
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

report('1. Все заявки', static function () use ($gdeslon): void {
    $list = $gdeslon->lostOrders();
    printf('   заявок: %d, пропущено: %d%s', count($list), count($list->skipped()), PHP_EOL);
});

report('2. Фильтры: период, в работе, ожидают', static function () use ($gdeslon): void {
    $list = $gdeslon->lostOrders(new LostOrderCriteria(from: '2026-07-01', until: '2026-10-07', claimState: LostOrderClaimState::InWork, orderStatus: LostOrderStatus::Waiting));
    printf('   заявок: %d%s', count($list), PHP_EOL);
});

report('3. Магазин вне справочника (ожидается LostOrderValidationException)', static function () use ($gdeslon): void {
    $gdeslon->lostOrders(new LostOrderCriteria(merchant: 999999999));
});

report('4. Несуществующая заявка (ожидается null)', static function () use ($gdeslon): void {
    var_dump($gdeslon->lostOrder(1));
});

report('5. Неверный токен (ожидается AuthenticationException)', static function (): void {
    GdeSlon::create(new Config(apiToken: 'not-a-real-token-0000'))->lostOrders();
});
