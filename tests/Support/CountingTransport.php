<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;

/**
 * Обёртка-шпион: считает запросы, которые прошли через настоящий транспорт.
 */
final class CountingTransport implements HttpTransport
{
    private int $count = 0;

    public function __construct(private readonly HttpTransport $inner)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->count++;

        return $this->inner->send($request);
    }

    public function count(): int
    {
        return $this->count;
    }
}
