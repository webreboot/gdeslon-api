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

/**
 * Общая часть адаптеров API: отправка, проверка статуса, разбор JSON в исключения пакета.
 *
 * @internal
 */
final class ApiClient
{
    /** Сколько байт тела ответа сохраняется в HttpException для диагностики. */
    private const SNIPPET_BYTES = 500;

    public function __construct(private readonly HttpTransport $transport)
    {
    }

    /**
     * @return mixed JSON, разобранный в ассоциативные массивы
     *
     * @throws GdeSlonException
     */
    public function requestJson(HttpRequest $request): mixed
    {
        return $this->decodeJson($request, $this->send($request, []));
    }

    /**
     * Ответ 2xx; остальные статусы, включая 304, — исключения, как в requestJson(). Для запросов, тело ответа которых
     * нужно проверить до разбора.
     *
     * @throws GdeSlonException
     */
    public function request(HttpRequest $request): HttpResponse
    {
        return $this->send($request, []);
    }

    /**
     * Ответ 2xx или со статусом из $statuses — его тело разбирает адаптер (например, 400 с ошибками полей, 404 «нет
     * такой записи»). 401/403 — всегда AuthenticationException, прочие статусы — HttpException.
     *
     * @throws GdeSlonException
     */
    public function requestAllowing(HttpRequest $request, int ...$statuses): HttpResponse
    {
        return $this->send($request, array_values(array_diff($statuses, [401, 403])));
    }

    /**
     * Начало тела ответа для сообщений и исключений — без секретов запроса.
     */
    public static function maskedSnippet(HttpRequest $request, HttpResponse $response): string
    {
        // сначала значения секретов во всём теле (до обрезки), затем параметры вида «_gs_at=…»
        $body = SecretMasker::maskValues($response->body(), SecretMasker::secretValues($request));

        return SecretMasker::maskText(self::snippet($body));
    }

    /**
     * Ответ 2xx или 304 (для условных запросов); остальные статусы — исключения, как в requestJson().
     *
     * @throws GdeSlonException
     */
    public function fetch(HttpRequest $request): HttpResponse
    {
        return $this->send($request, [304]);
    }

    /**
     * @throws UnexpectedResponseException тело не JSON
     */
    public function decodeJson(HttpRequest $request, HttpResponse $response): mixed
    {
        try {
            // большие целые (ID) — строкой, без потери точности во float
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
     * @throws GdeSlonException
     */
    /**
     * @param list<int> $allowed статусы, которые возвращаются как ответ, а не исключение
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

    /**
     * Начало тела ответа. Текст в UTF-8 режется по границе символа (без ext-mbstring), прочее — по байтам.
     */
    private static function snippet(string $body): string
    {
        $snippet = substr($body, 0, self::SNIPPET_BYTES);
        if (preg_match('//u', $body) !== 1) {
            return $snippet;
        }

        // UTF-8 символ занимает до 4 байт: отрезаем максимум 3 байта недописанного символа
        for ($i = 0; $i < 3 && preg_match('//u', $snippet) !== 1; $i++) {
            $snippet = substr($snippet, 0, -1);
        }

        return $snippet;
    }
}
