<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\GdeSlon;

final class CurlTransport implements HttpTransport
{
    private readonly string $userAgent;

    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        ?string $userAgent = null,
    ) {
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Таймауты должны быть положительными, получено timeout=%s, connectTimeout=%s',
                $timeout,
                $connectTimeout,
            ));
        }

        $this->userAgent = $userAgent ?? self::defaultUserAgent();
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->timeout(), $config->connectTimeout(), $config->userAgent());
    }

    public static function defaultUserAgent(): string
    {
        return sprintf(
            'webreboot-gdeslon-api/%s (+https://github.com/webreboot/gdeslon-api) PHP/%s',
            GdeSlon::VERSION,
            PHP_VERSION,
        );
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $headers = new HeaderParser();
        $handle = curl_init();

        curl_setopt_array($handle, CurlOptions::for(
            $request,
            $this->timeout,
            $this->connectTimeout,
            $this->userAgent,
            static function (\CurlHandle $handle, string $line) use ($headers): int {
                $headers->addLine($line);

                return strlen($line);
            },
        ));

        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw CurlErrorMapper::toException($request, curl_errno($handle), curl_error($handle));
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $headers->headers());
    }
}
