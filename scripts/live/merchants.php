<?php

declare(strict_types=1);

// Запуск: printf '%s' "$GDESLON_API_TOKEN" | ssh <сервер> 'cd gdeslon-api && docker compose exec -T php81 php scripts/live/merchants.php'

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$token = trim((string) stream_get_contents(STDIN));
if ($token === '') {
    fwrite(STDERR, "Передайте токен XML API через STDIN\n");
    exit(2);
}

// адреса запросов не логируем: в них токен
final class LiveSpy implements HttpTransport
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(private readonly HttpTransport $inner)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $started = microtime(true);
        $response = $this->inner->send($request);
        $this->log[] = sprintf('HTTP %d, %d байт, %.2f с', $response->statusCode(), strlen($response->body()), microtime(true) - $started);

        return $response;
    }
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

$links = static fn (iterable $list): int => count(array_filter(is_array($list) ? $list : iterator_to_array($list), static fn (Merchant $m): bool => $m->affiliateLink() !== null));

echo 'PHP ', PHP_VERSION, ', libxml ', LIBXML_DOTTED_VERSION, PHP_EOL;

report('1. С токеном', static function () use ($token, $links): void {
    $spy = new LiveSpy(TransportFactory::fromConfig(new Config()));
    $list = GdeSlon::create(new Config(apiToken: $token), $spy)->merchants();
    printf('   магазинов: %d, с партнёрской ссылкой: %d, категорий магазинов: %d, тарифов: %d%s',
        count($list), $links($list), count($list->categories()),
        array_sum(array_map(static fn (Merchant $m): int => count($m->tariffs()), $list->all())), PHP_EOL);
    printf('   поиск «ali»: %d, домен komus.ru: %d%s', count($list->search('ali')), count($list->findByDomain('komus.ru')), PHP_EOL);
    printf('   пропущено записей: %d%s', count($list->skipped()), PHP_EOL);
    echo '   ', implode(PHP_EOL . '   ', $spy->log), PHP_EOL;
});

report('2. Без токена (публичный каталог)', static function () use ($links): void {
    $list = GdeSlon::create()->merchants();
    printf('   магазинов: %d, с партнёрской ссылкой: %d%s', count($list), $links($list), PHP_EOL);
});

report('3. Неверный токен (ожидается AuthenticationException)', static function (): void {
    $list = GdeSlon::create(new Config(apiToken: 'not-a-real-token'))->merchants();
    printf('   неожиданно: %d магазинов%s', count($list), PHP_EOL);
});

report('4. Кэш: загрузка, свежий кэш, условный запрос', static function () use ($token): void {
    $directory = sys_get_temp_dir() . '/gdeslon-live-' . bin2hex(random_bytes(4));
    $store = new FileCacheStore($directory);
    foreach ([['первый вызов', 3600], ['второй (свежий кэш)', 3600], ['ttl 0 (условный запрос)', 0]] as [$label, $ttl]) {
        $spy = new LiveSpy(TransportFactory::fromConfig(new Config()));
        $list = GdeSlon::create(new Config(apiToken: $token, merchantCacheTtl: $ttl), $spy, $store)->merchants();
        printf('   %s: %d магазинов, запросов %d %s%s', $label, count($list), count($spy->log), implode('; ', $spy->log), PHP_EOL);
    }
    $leak = false;
    foreach (glob($directory . '/*') ?: [] as $file) {
        $leak = $leak || str_contains(basename($file) . (string) file_get_contents($file), $token);
        unlink($file);
    }
    @rmdir($directory);
    echo '   токен в кэше: ', $leak ? 'ДА — ОШИБКА' : 'нет', PHP_EOL;
});
