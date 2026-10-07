<?php

declare(strict_types=1);

// Запуск: make remote-run CMD="php scripts/live/categories.php"

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
        $this->log[] = sprintf('%s %s → %d, %d байт, %.2f с', $request->method(), $request->header('Range') ?? '(без Range)', $response->statusCode(), strlen($response->body()), microtime(true) - $started);

        return $response;
    }
}

function step(string $title, callable $check): void
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

step('1. Загрузка частями по умолчанию', static function (): void {
    $spy = new LiveSpy(TransportFactory::fromConfig(new Config(rangeChunkSize: null)));
    $tree = GdeSlon::create(transport: new RangeTransport($spy))->categories();
    printf('   категорий: %d, корней: %d, сирот: %d, запросов: %d%s', count($tree), count($tree->roots()), count($tree->orphans()), count($spy->log), PHP_EOL);
    echo '   ', implode(PHP_EOL . '   ', $spy->log), PHP_EOL;
});

step('2. Контроль: одним запросом (ожидается TimeoutException ~16 КБ)', static function (): void {
    $tree = GdeSlon::create(new Config(timeout: 8.0, rangeChunkSize: null))->categories();
    printf('   неожиданно загрузилось: %d категорий%s', count($tree), PHP_EOL);
});

step('3. Кэш: загрузка, затем из кэша, затем условный запрос', static function (): void {
    $directory = sys_get_temp_dir() . '/gdeslon-live-' . bin2hex(random_bytes(4));
    $store = new FileCacheStore($directory);

    foreach ([['первый вызов', 86400], ['второй вызов (свежий кэш)', 86400], ['ttl 0 (условный запрос)', 0]] as [$label, $ttl]) {
        $spy = new LiveSpy(TransportFactory::fromConfig(new Config(rangeChunkSize: null)));
        $tree = GdeSlon::create(new Config(cacheTtl: $ttl), new RangeTransport($spy), $store)->categories();
        printf('   %s: %d категорий, запросов %d%s', $label, count($tree), count($spy->log), PHP_EOL);
        foreach ($spy->log as $line) {
            echo '     ', $line, PHP_EOL;
        }
    }

    array_map('unlink', glob($directory . '/*') ?: []);
    @rmdir($directory);
});
