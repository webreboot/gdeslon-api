<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;

/**
 * Ошибка cURL → исключение пакета без секретов в тексте.
 *
 * @internal
 */
final class CurlErrorMapper
{
    private const OPERATION_TIMEDOUT = 28;

    public static function toException(HttpRequest $request, int $code, string $error): TransportException
    {
        $url = $request->maskedUri();
        $reason = $code === self::OPERATION_TIMEDOUT ? 'превышено время ожидания' : 'ошибка соединения';
        $message = sprintf('%s %s: %s (cURL %d: %s)', $request->method(), $url, $reason, $code, SecretMasker::maskText($error));

        return $code === self::OPERATION_TIMEDOUT
            ? new TimeoutException($message, $request->method(), $url, $code)
            : new TransportException($message, $request->method(), $url, $code);
    }
}
