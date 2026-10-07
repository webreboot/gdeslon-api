<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * HTTP-запрос к API. Неизменяемый. var_dump/print_r не показывают секреты (Authorization, токены в query).
 */
final class HttpRequest
{
    private const DEBUG_BODY_BYTES = 1024;

    private readonly string $method;

    /** Не дольше скольких секунд ждать ответа на этот запрос (подсказка транспорту); null — как у транспорта. */
    private ?float $timeout = null;

    /**
     * @param array<string, scalar|list<scalar>> $query параметры строки запроса (RFC 3986); список — повтор параметра
     *                                                 (`merchant_id=1&merchant_id=2`), пустой список — параметра нет
     * @param array<string, string> $headers заголовки «имя => значение»
     * @param string|null           $body    тело запроса как есть (JSON); null — без тела
     */
    public function __construct(
        string $method,
        private readonly string $url,
        private readonly array $query = [],
        private readonly array $headers = [],
        private readonly ?string $body = null,
    ) {
        $this->method = strtoupper($method);
    }

    /**
     * @param array<string, scalar|list<scalar>> $query
     * @param array<string, string>              $headers
     */
    public static function get(string $url, array $query = [], array $headers = []): self
    {
        return new self('GET', $url, $query, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function post(string $url, string $body, array $headers = []): self
    {
        return new self('POST', $url, [], $headers, $body);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return array<string, scalar|list<scalar>>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * Копия запроса с другими заголовками (метод, адрес, query, тело и таймаут сохраняются).
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $copy = new self($this->method, $this->url, $this->query, $headers, $this->body);
        $copy->timeout = $this->timeout;

        return $copy;
    }

    /**
     * Значение заголовка запроса без учёта регистра имени.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Копия запроса, ответ на который нужно ждать не дольше $timeout секунд (транспорт берёт меньший из таймаутов).
     */
    public function withTimeout(float $timeout): self
    {
        if ($timeout <= 0) {
            throw new InvalidArgumentException(sprintf('Таймаут запроса должен быть положительным, получено %s', $timeout));
        }

        $copy = $this->withHeaders($this->headers);
        $copy->timeout = $timeout;

        return $copy;
    }

    public function timeout(): ?float
    {
        return $this->timeout;
    }

    /**
     * Полный адрес запроса вместе со строкой запроса.
     */
    public function uri(): string
    {
        if ($this->query === []) {
            return $this->url;
        }

        $pairs = [];
        foreach ($this->query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = http_build_query([$name => is_bool($item) ? (int) $item : $item], '', '&', PHP_QUERY_RFC3986);
            }
        }
        if ($pairs === []) {
            return $this->url;
        }

        return $this->url . (str_contains($this->url, '?') ? '&' : '?') . implode('&', $pairs);
    }

    /**
     * Адрес запроса без секретов — для сообщений и логов.
     */
    public function maskedUri(): string
    {
        return SecretMasker::maskUrl($this->uri());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[$name] = strcasecmp($name, 'Authorization') === 0 ? '***' : $value;
        }

        return [
            'method' => $this->method,
            'uri' => $this->maskedUri(),
            'headers' => $headers,
            'body' => $this->debugBody(),
            'timeout' => $this->timeout,
        ];
    }

    /**
     * Тело для дампа: multipart (файлы, данные заказа) и большие тела — только размером.
     */
    private function debugBody(): ?string
    {
        if ($this->body === null) {
            return null;
        }
        $multipart = str_starts_with(strtolower(ltrim($this->header('Content-Type') ?? '')), 'multipart/');

        return $multipart || strlen($this->body) > self::DEBUG_BODY_BYTES ? sprintf('<%d байт>', strlen($this->body)) : $this->body;
    }
}
