<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

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

    public function field(): ?string
    {
        return $this->field;
    }
}
