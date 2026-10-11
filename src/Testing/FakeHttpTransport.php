<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Testing;

use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\CurlErrorMapper;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;

final class FakeHttpTransport implements HttpTransport
{
    /** @var list<HttpResponse|TransportException|array{int, string}> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    private array $requests = [];

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function willReturn(int $status, #[\SensitiveParameter] string $body, array $headers = []): self
    {
        $this->queue[] = new HttpResponse($status, $body, array_map(static fn (string|array $values): array => (array) $values, $headers));

        return $this;
    }

    public function willThrow(TransportException $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function willFail(int $curlErrorCode, string $message = 'Failed to connect'): self
    {
        $this->queue[] = [$curlErrorCode, $message];

        return $this;
    }

    /**
     * @throws TransportException
     * @throws \LogicException
     */
    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeHttpTransport: неожиданный запрос ' . $request->method() . ' ' . $request->maskedUri());
        }
        if ($next instanceof TransportException) {
            throw $next;
        }
        if (is_array($next)) {
            throw CurlErrorMapper::toException($request, $next[0], $next[1]);
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

    /**
     * @throws \LogicException
     */
    public function lastRequest(): HttpRequest
    {
        return $this->requests[count($this->requests) - 1] ?? throw new \LogicException('FakeHttpTransport: запросов не было');
    }
}
