<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class RetryOnConnectTimeoutTransport implements HttpTransport
{
    public const MAX_RETRIES = 5;

    private const SAFE_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly int $retries = 1,
    ) {
        if ($retries < 0 || $retries > self::MAX_RETRIES) {
            throw new InvalidArgumentException(sprintf(
                'Число повторов при таймауте соединения должно быть от 0 до %d, получено %d',
                self::MAX_RETRIES,
                $retries,
            ));
        }
    }

    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        if ($this->retries === 0 || !in_array($request->method(), self::SAFE_METHODS, true)) {
            return $this->transport->send($request);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->transport->send($request);
            } catch (ConnectTimeoutException $e) {
                if ($attempt > $this->retries) {
                    throw new ConnectTimeoutException(
                        sprintf('%s; попыток: %d', $e->getMessage(), $attempt),
                        $e->method(),
                        $e->url(),
                        $e->curlErrorCode(),
                        $e,
                    );
                }
            }
        }
    }
}
