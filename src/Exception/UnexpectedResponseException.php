<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Exception;

/**
 * Ответ получен, но его нельзя разобрать: битый или обрезанный JSON, неожиданная форма данных.
 */
final class UnexpectedResponseException extends \RuntimeException implements GdeSlonException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
