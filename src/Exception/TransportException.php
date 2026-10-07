<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Exception;

class TransportException extends \RuntimeException implements GdeSlonException
{
    public function __construct(
        string $message,
        private readonly string $method,
        private readonly string $url,
        private readonly ?int $curlErrorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function curlErrorCode(): ?int
    {
        return $this->curlErrorCode;
    }
}
