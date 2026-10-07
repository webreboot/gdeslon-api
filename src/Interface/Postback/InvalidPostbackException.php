<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/**
 * Запрос postback не разобрать: нет или битые merchant_id/state (400), битое тело (400), не тот метод (405), слишком
 * большое тело (413), неподдерживаемый Content-Type (415).
 */
final class InvalidPostbackException extends PostbackException
{
    public function __construct(string $message, int $responseStatus = 400, private readonly ?string $field = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $responseStatus, $previous);
    }

    public static function forField(string $field, string $message): self
    {
        return new self($message, 400, $field);
    }

    /**
     * Макрос или имя параметра, к которому относится ошибка; null — ошибка запроса целиком.
     */
    public function field(): ?string
    {
        return $this->field;
    }
}
