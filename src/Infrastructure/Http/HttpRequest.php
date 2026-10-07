<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class HttpRequest
{
    private const DEBUG_BODY_BYTES = 1024;

    private readonly string $method;

    private ?float $timeout = null;

    /**
     * @param array<string, scalar|list<scalar>> $query
     * @param array<string, string>              $headers
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
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $copy = new self($this->method, $this->url, $this->query, $headers, $this->body);
        $copy->timeout = $this->timeout;

        return $copy;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

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

    private function debugBody(): ?string
    {
        if ($this->body === null) {
            return null;
        }
        $multipart = str_starts_with(strtolower(ltrim($this->header('Content-Type') ?? '')), 'multipart/');

        return $multipart || strlen($this->body) > self::DEBUG_BODY_BYTES ? sprintf('<%d байт>', strlen($this->body)) : $this->body;
    }
}
