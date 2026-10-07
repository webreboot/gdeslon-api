<?php

declare(strict_types=1);

// Запуск: printf '%s\n%s\n' "$GDESLON_USER_ID" "$GDESLON_API_KEY" | ssh <сервер> 'cd gdeslon-api && docker compose exec -T php81 php scripts/live/orders.php'

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\GdeSlon;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$lines = preg_split('/\R/', trim((string) stream_get_contents(STDIN))) ?: [];
[$userId, $apiKey] = array_map('trim', array_pad($lines, 2, ''));
if ($userId === '' || $apiKey === '') {
    fwrite(STDERR, "Передайте ID пользователя и ключ API по продажам через STDIN (две строки)\n");
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
$gdeslon = GdeSlon::create(new Config(userId: $userId, apiKey: $apiKey));

report('1. По умолчанию (created_at, 30 дней)', static function () use ($gdeslon): void {
    $orders = $gdeslon->orders();
    printf('   заказов: %d, пропущено: %d%s', count($orders), count($orders->skipped()), PHP_EOL);
    foreach ($orders->skipped() as $reason) {
        echo '   пропуск: ', $reason, PHP_EOL;
    }
});

report('2. Все фильтры (last_updated_at, 3660 дней, магазин, статусы, sub_id)', static function () use ($gdeslon): void {
    $orders = $gdeslon->orders(new OrderCriteria(
        dateField: OrderDateField::LastUpdated,
        days: OrderCriteria::MAX_DAYS,
        merchant: 2573,
        states: [OrderState::Confirmed, OrderState::Paid],
        subId: 'тест',
    ));
    printf('   заказов: %d, пропущено: %d%s', count($orders), count($orders->skipped()), PHP_EOL);
});

report('3. Все поля дат за 3660 дней', static function () use ($gdeslon): void {
    foreach (OrderDateField::cases() as $field) {
        $orders = $gdeslon->orders(new OrderCriteria(dateField: $field, days: OrderCriteria::MAX_DAYS));
        printf('   %s: заказов %d, пропущено %d%s', $field->value, count($orders), count($orders->skipped()), PHP_EOL);
    }
});

report('4. Неверный ключ (ожидается AuthenticationException)', static function () use ($userId): void {
    GdeSlon::create(new Config(userId: $userId, apiKey: 'not-a-real-key-0000'))->orders();
});
