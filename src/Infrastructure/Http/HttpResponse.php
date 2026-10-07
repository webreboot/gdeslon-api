<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

/**
 * Ответ сервера. Любой статус, включая 4xx/5xx, — обычный ответ, а не исключение.
 */
final class HttpResponse
{
    /** @var array<string, list<string>> */
    private readonly array $headers;

    /**
     * @param array<string, list<string>> $headers имя => значения (имя в любом регистре, повторы сохраняются)
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body,
        array $headers,
    ) {
        $normalized = [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $normalized[strtolower($name)][] = $value;
            }
        }
        $this->headers = $normalized;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, list<string>> имена заголовков в нижнем регистре
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Первое значение заголовка (имя без учёта регистра) или null.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @return list<string> все значения заголовка в порядке получения
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
