<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

/** @internal */
final class CurlOptions
{
    /**
     * @param \Closure(\CurlHandle, string): int $headerFunction
     *
     * @return array<int, mixed>
     */
    public static function for(
        HttpRequest $request,
        float $timeout,
        float $connectTimeout,
        string $userAgent,
        \Closure $headerFunction,
    ): array {
        $headers = [];
        foreach ($request->headers() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $request->uri(),
            CURLOPT_CUSTOMREQUEST => $request->method(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => $headerFunction,
            CURLOPT_TIMEOUT_MS => (int) round(min($timeout, $request->timeout() ?? $timeout) * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($connectTimeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // пустая строка: libcurl предлагает все поддерживаемые кодировки и распаковывает ответ
            CURLOPT_ACCEPT_ENCODING => '',
        ];
        if ($request->body() !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body();
        }

        return $options;
    }
}
