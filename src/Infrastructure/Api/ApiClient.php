<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api;

use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\SecretMasker;

/** @internal */
final class ApiClient
{
    private const SNIPPET_BYTES = 500;

    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function requestJson(HttpRequest $request): mixed
    {
        return $this->decodeJson($request, $this->send($request, []));
    }

    public function request(HttpRequest $request): HttpResponse
    {
        return $this->send($request, []);
    }

    public function requestAllowing(HttpRequest $request, int ...$statuses): HttpResponse
    {
        return $this->send($request, array_values(array_diff($statuses, [401, 403])));
    }

    public static function maskedSnippet(HttpRequest $request, HttpResponse $response): string
    {
        $body = SecretMasker::maskValues($response->body(), SecretMasker::secretValues($request));

        return SecretMasker::maskText(self::snippet($body));
    }

    public function fetch(HttpRequest $request): HttpResponse
    {
        return $this->send($request, [304]);
    }

    public function decodeJson(HttpRequest $request, HttpResponse $response): mixed
    {
        try {
            return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new UnexpectedResponseException(sprintf(
                '%s %s: ответ не является корректным JSON (получено %d байт): %s',
                $request->method(),
                $request->maskedUri(),
                strlen($response->body()),
                $e->getMessage(),
            ), $e);
        }
    }

    /**
     * @param list<int> $allowed
     */
    private function send(HttpRequest $request, array $allowed): HttpResponse
    {
        $response = $this->transport->send($request);
        if ($response->isSuccessful() || in_array($response->statusCode(), $allowed, true)) {
            return $response;
        }

        $status = $response->statusCode();
        $snippet = self::maskedSnippet($request, $response);

        if ($status === 401 || $status === 403) {
            throw new AuthenticationException(
                sprintf('%s %s: HTTP %d — ключ API не указан, неверен или не даёт доступа', $request->method(), $request->maskedUri(), $status),
                $status,
                $request->method(),
                $request->maskedUri(),
                $snippet,
            );
        }

        throw new HttpException(
            sprintf('%s %s: HTTP %d', $request->method(), $request->maskedUri(), $status),
            $status,
            $request->method(),
            $request->maskedUri(),
            $snippet,
        );
    }

    private static function snippet(string $body): string
    {
        $snippet = substr($body, 0, self::SNIPPET_BYTES);
        if (preg_match('//u', $body) !== 1) {
            return $snippet;
        }

        for ($i = 0; $i < 3 && preg_match('//u', $snippet) !== 1; $i++) {
            $snippet = substr($snippet, 0, -1);
        }

        return $snippet;
    }
}
