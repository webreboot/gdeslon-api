<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Postback не принят. responseStatus() — HTTP-код, которым ответить «Где Слон?»; сообщение можно отдать в теле ответа
 * (оно видно в окне теста кабинета) — как простой текст (Content-Type: text/plain), не HTML: в нём бывают фрагменты
 * запроса. Секрета и значений sub_id в нём нет.
 */
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
