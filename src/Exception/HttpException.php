<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Exception;

class HttpException extends \RuntimeException implements GdeSlonException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $method,
        private readonly string $url,
        private readonly string $responseSnippet,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function responseSnippet(): string
    {
        return $this->responseSnippet;
    }
}
