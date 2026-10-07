<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Exception;

/**
 * Запрос не дошёл или ответ не получен целиком: нет сети, DNS, отказ соединения, ошибка TLS, обрыв.
 * URL в сообщении и в url() — без секретов.
 */
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

    /**
     * URL запроса с замаскированными токенами.
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * Код ошибки cURL (CURLE_*), если ошибку дал cURL.
     */
    public function curlErrorCode(): ?int
    {
        return $this->curlErrorCode;
    }
}
