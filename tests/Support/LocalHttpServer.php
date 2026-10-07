<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

/**
 * Встроенный сервер PHP (php -S) на свободном порту 127.0.0.1 — для тестов транспорта без внешней сети.
 */
final class LocalHttpServer
{
    /** @var resource */
    private $process;

    private function __construct(private readonly int $port)
    {
        $router = __DIR__ . '/HttpServer/router.php';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить php -S');
        }
        $this->process = $process;
    }

    public static function start(): self
    {
        $server = new self(self::freePort());
        // если прогон прервётся до tearDownAfterClass, сервер не останется жить в постоянном контейнере
        register_shutdown_function([$server, 'stop']);
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $server->port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return $server;
            }
            usleep(20_000);
        }
        $server->stop();

        throw new \RuntimeException('php -S не начал слушать порт ' . $server->port);
    }

    /**
     * Порт, который сейчас никто не слушает.
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('Нет свободного порта: ' . (is_string($error) ? $error : ''));
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
