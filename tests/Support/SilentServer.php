<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

final class SilentServer
{
    /** @var list<resource> */
    private array $clients = [];

    /**
     * @param resource $socket
     */
    private function __construct(private $socket, private readonly int $port)
    {
    }

    public static function start(): self
    {
        return self::listen(128);
    }

    public static function withFullAcceptQueue(): self
    {
        $server = self::listen(0);
        for ($k = 0; $k < 4; $k++) {
            $client = @stream_socket_client('tcp://127.0.0.1:' . $server->port, $errno, $error, 0.2, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if (is_resource($client)) {
                $server->clients[] = $client;
            }
        }
        usleep(200_000);

        return $server;
    }

    public function url(string $scheme = 'http'): string
    {
        return $scheme . '://127.0.0.1:' . $this->port . '/';
    }

    public function proxy(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        foreach ($this->clients as $client) {
            fclose($client);
        }
        $this->clients = [];
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    private static function listen(int $backlog): self
    {
        $context = stream_context_create(['socket' => ['backlog' => $backlog]]);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($socket === false) {
            throw new \RuntimeException('Не удалось открыть сокет: ' . (is_string($error) ? $error : ''));
        }
        $name = (string) stream_socket_get_name($socket, false);

        return new self($socket, (int) substr($name, (int) strrpos($name, ':') + 1));
    }
}
