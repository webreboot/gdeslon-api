<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\HttpException;

/**
 * API отклонило запрос заявок с ошибками полей (HTTP 400 `{"errors": {...}}`). При создании — заявка не создана.
 */
final class LostOrderValidationException extends HttpException
{
    /**
     * @param array<string, list<string>> $errors поле → сообщения API
     */
    public function __construct(
        string $message,
        int $statusCode,
        string $method,
        string $url,
        string $responseSnippet,
        private readonly array $errors,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $method, $url, $responseSnippet, $previous);
    }

    /**
     * @return array<string, list<string>> поле (`order_date`, `merchant_id`, `detail`…) → сообщения API
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
