<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Exception\GdeSlonException;

class PostbackException extends \RuntimeException implements GdeSlonException
{
    public function __construct(string $message, private readonly int $responseStatus, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function responseStatus(): int
    {
        return $this->responseStatus;
    }
}
