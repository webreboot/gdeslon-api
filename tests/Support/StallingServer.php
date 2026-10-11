<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

final class StallingServer
{
    /**
     * @param resource $process
     */
    private function __construct(private $process, private readonly int $port)
    {
    }

    public static function proxyEstablishingTunnel(): self
    {
        return self::start('connect-established');
    }

    public static function partialBody(): self
    {
        return self::start('partial-body');
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function proxy(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private static function start(string $mode): self
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/HttpServer/stalling.php', $mode],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process) || !is_array($pipes) || !is_resource($pipes[1] ?? null)) {
            throw new \RuntimeException('Не удалось запустить ' . $mode);
        }
        $output = $pipes[1];
        stream_set_timeout($output, 3);
        $port = (int) fgets($output);
        fclose($output);
        $server = new self($process, $port);
        if ($port <= 0) {
            $server->stop();

            throw new \RuntimeException('Сервер ' . $mode . ' не сообщил порт');
        }
        register_shutdown_function([$server, 'stop']);

        return $server;
    }
}
