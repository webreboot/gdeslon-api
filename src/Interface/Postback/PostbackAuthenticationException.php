<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/**
 * Заголовок с секретом отсутствует, повторён или неверен — ответ 401.
 */
final class PostbackAuthenticationException extends PostbackException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 401);
    }
}
