<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

final class PostbackAuthenticationException extends PostbackException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 401);
    }
}
