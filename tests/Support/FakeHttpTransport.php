<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;

/**
 * Транспорт без сети: отдаёт заранее заданные ответы по очереди и запоминает запросы.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<HttpResponse|TransportException> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    private array $requests = [];

    /**
     * @param array<string, list<string>> $headers
     */
    public function willReturn(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new HttpResponse($status, $body, $headers);

        return $this;
    }

    public function willThrow(TransportException $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeHttpTransport: неожиданный запрос ' . $request->method() . ' ' . $request->uri());
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<HttpRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): HttpRequest
    {
        return $this->requests[count($this->requests) - 1] ?? throw new \LogicException('Запросов не было');
    }
}
