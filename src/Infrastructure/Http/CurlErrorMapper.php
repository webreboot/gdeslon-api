<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;

/** @internal */
final class CurlErrorMapper
{
    private const OPERATION_TIMEDOUT = 28;

    public static function toException(
        #[\SensitiveParameter]
        HttpRequest $request,
        int $code,
        #[\SensitiveParameter]
        string $error,
        bool $connecting = false,
    ): TransportException {
        $url = $request->maskedUri();
        $reason = match (true) {
            $code !== self::OPERATION_TIMEDOUT => 'ошибка соединения',
            $connecting => 'превышено время установки соединения',
            default => 'превышено время ожидания',
        };
        $message = sprintf('%s %s: %s (cURL %d: %s)', $request->method(), $url, $reason, $code, SecretMasker::maskText($error));

        return match (true) {
            $code !== self::OPERATION_TIMEDOUT => new TransportException($message, $request->method(), $url, $code),
            $connecting => new ConnectTimeoutException($message, $request->method(), $url, $code),
            default => new TimeoutException($message, $request->method(), $url, $code),
        };
    }
}
