<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;

/**
 * Статический файл за nginx, как gdeslon-categories.json (docs/gdeslon-api/categories.md, «Диапазоны и условные
 * запросы»): один диапазон → 206 с Content-Range; начало за размером → 416 (звёздочка вместо диапазона); If-Match не совпал → 412;
 * If-None-Match совпал → 304; без Range → 200 целиком.
 */
final class FakeRangeServer implements HttpTransport
{
    public const ETAG = '"6724af75-26edb"';
    public const LAST_MODIFIED = 'Fri, 01 Nov 2024 10:37:41 GMT';

    /** @var list<HttpRequest> */
    private array $requests = [];

    private bool $ignoreRange = false;

    private ?int $changeAfter = null;

    private bool $honourIfMatch = true;

    /** @var array<int, array{TransportException, int}> смещение части => [исключение, сколько раз] */
    private array $failures = [];

    /** @var array<int, HttpResponse> номер запроса (с 1) => подменённый ответ */
    private array $overrides = [];

    public function __construct(
        private string $body,
        private ?string $etag = self::ETAG,
        private readonly ?string $lastModified = self::LAST_MODIFIED,
    ) {
    }

    /**
     * Сервер не поддерживает Range — как Express на search.xml: 200, chunked, без ETag.
     */
    public function ignoreRange(): self
    {
        $this->ignoreRange = true;
        $this->etag = null;

        return $this;
    }

    /**
     * После $requests запросов файл меняется (другой ETag и содержимое той же длины).
     */
    public function changeAfter(int $requests, bool $honourIfMatch = false): self
    {
        $this->changeAfter = $requests;
        $this->honourIfMatch = $honourIfMatch;

        return $this;
    }

    public function failPart(int $offset, TransportException $exception, int $times = 1): self
    {
        $this->failures[$offset] = [$exception, $times];

        return $this;
    }

    public function override(int $requestNumber, HttpResponse $response): self
    {
        $this->overrides[$requestNumber] = $response;

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $number = count($this->requests);

        if ($this->changeAfter !== null && $number > $this->changeAfter) {
            $this->etag = '"changed"';
            $this->body = strtoupper($this->body);
        }

        $range = $this->parseRange($request->header('Range'));
        if ($range !== null && isset($this->failures[$range[0]]) && $this->failures[$range[0]][1] > 0) {
            $this->failures[$range[0]][1]--;

            throw $this->failures[$range[0]][0];
        }

        if (isset($this->overrides[$number])) {
            return $this->overrides[$number];
        }

        if ($this->ignoreRange) {
            return new HttpResponse(200, $this->body, [
                'Content-Type' => ['text/xml; charset=utf-8'],
                'Transfer-Encoding' => ['chunked'],
                'Connection' => ['close'],
                'X-Powered-By' => ['Express'],
            ]);
        }

        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch !== null && $ifNoneMatch === $this->etag) {
            return new HttpResponse(304, '', $this->validators());
        }

        $ifMatch = $request->header('If-Match');
        if ($this->honourIfMatch && $ifMatch !== null && $ifMatch !== $this->etag) {
            return new HttpResponse(412, '<html>412 Precondition Failed</html>', ['Content-Type' => ['text/html']]);
        }

        $total = strlen($this->body);
        if ($range === null) {
            return new HttpResponse(200, $this->body, $this->headers() + ['Content-Length' => [(string) $total]]);
        }

        [$start, $end] = $range;
        if ($start >= $total) {
            return new HttpResponse(416, '<html>416</html>', [
                'Content-Type' => ['text/html'],
                'Content-Range' => ['bytes */' . $total],
            ]);
        }
        $end = min($end, $total - 1);

        return new HttpResponse(206, substr($this->body, $start, $end - $start + 1), $this->headers() + [
            'Content-Length' => [(string) ($end - $start + 1)],
            'Content-Range' => [sprintf('bytes %d-%d/%d', $start, $end, $total)],
        ]);
    }

    /**
     * @return list<HttpRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<string|null> заголовок Range каждого запроса
     */
    public function ranges(): array
    {
        return array_map(static fn (HttpRequest $request): ?string => $request->header('Range'), $this->requests);
    }

    /**
     * @return array{int, int}|null
     */
    private function parseRange(?string $header): ?array
    {
        if ($header === null || preg_match('~^bytes=(\d+)-(\d+)$~', $header, $match) !== 1) {
            return null;
        }

        return [(int) $match[1], (int) $match[2]];
    }

    /**
     * @return array<string, list<string>>
     */
    private function headers(): array
    {
        // реальный ответ содержит Content-Type дважды
        return ['Content-Type' => ['application/json', 'application/json; charset=utf-8']] + $this->validators();
    }

    /**
     * @return array<string, list<string>>
     */
    private function validators(): array
    {
        $headers = [];
        if ($this->etag !== null) {
            $headers['ETag'] = [$this->etag];
        }
        if ($this->lastModified !== null) {
            $headers['Last-Modified'] = [$this->lastModified];
        }

        return $headers;
    }
}
