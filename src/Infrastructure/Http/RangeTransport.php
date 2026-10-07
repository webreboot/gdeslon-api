<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;

/**
 * Загрузка больших ответов частями (HTTP Range) — обход сетей, где одно соединение с api.gdeslon.ru зависает после
 * ~16 КБ (docs/gdeslon-api/categories.md, «Обрыв загрузки»).
 *
 * Для GET на хосты из списка каждая часть идёт отдельным соединением (`Connection: close`; ограничение действует на
 * соединение). Целостность: общий размер из Content-Range, ETag/Last-Modified каждой части, `If-Match` при сильном ETag.
 * Части со второй при ошибке повторяются, при таймауте — вдвое меньшим размером. Первая часть не повторяется: время
 * отказа то же, что без загрузки частями. Если сервер не поддерживает Range (200 на первую часть) — ответ возвращается
 * как есть; ответы без ETag и Last-Modified не склеиваются (могут отличаться между запросами).
 *
 * Внутренний транспорт должен соблюдать HttpRequest::timeout() (таймаут части) и не переиспользовать соединение при
 * `Connection: close` — CurlTransport так и делает. Нужен HTTP/1.1: в HTTP/2 `Connection: close` игнорируется и части
 * пошли бы одним соединением.
 */
final class RangeTransport implements HttpTransport
{
    /** Ниже этого размера часть при таймаутах не уменьшается. */
    public const MIN_CHUNK = 4096;

    /** Хосты, для которых загрузка частями включена по умолчанию (обрыв соединения после ~16 КБ). */
    public const DEFAULT_HOSTS = ['api.gdeslon.ru'];

    /** Больше этого размера ответ частями не собирается (тело держится в памяти). */
    public const MAX_BYTES = 32 * 1024 * 1024;

    /** Заголовки исходного запроса, которые не переносятся в части. */
    private const PART_EXCLUDED_HEADERS = ['range', 'connection', 'if-match', 'if-range', 'if-none-match', 'if-modified-since'];

    /** @var list<string> */
    private readonly array $hosts;

    /**
     * @param HttpTransport $transport   транспорт, которым отправляется каждая часть
     * @param int           $chunkSize   размер части, байт (≥ 1024)
     * @param int           $retries     сколько раз повторять часть после ошибки (≥ 0)
     * @param float         $partTimeout таймаут каждой части, начиная со второй, секунды
     * @param list<string>  $hosts       для каких хостов включена загрузка частями
     */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly int $chunkSize = 16384,
        private readonly int $retries = 2,
        private readonly float $partTimeout = 10.0,
        array $hosts = self::DEFAULT_HOSTS,
    ) {
        if ($chunkSize < 1024 || $retries < 0 || $partTimeout <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Неверные настройки загрузки частями: chunkSize=%d (≥ 1024), retries=%d (≥ 0), partTimeout=%s (> 0)',
                $chunkSize,
                $retries,
                $partTimeout,
            ));
        }
        if ($hosts === [] || in_array('', $hosts, true)) {
            throw new InvalidArgumentException('Список хостов для загрузки частями должен быть непустым');
        }

        $this->hosts = array_map('strtolower', $hosts);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        if (!$this->applies($request)) {
            return $this->transport->send($request);
        }

        // первая часть не повторяется: если ответа нет, хост недоступен или сервер игнорирует Range и шлёт весь
        // ответ (search.xml) — повтор лишь умножил бы время отказа
        $chunk = $this->chunkSize;
        $first = $this->transport->send($this->partRequest($request, 0, $chunk, null, false));

        if ($first->statusCode() === 416) {
            return $this->transport->send($request);
        }
        if ($first->statusCode() !== 206) {
            return $first;
        }

        $range = $this->assertPart($request, $first, 0, sprintf('bytes=0-%d', $this->chunkSize - 1));
        $total = $range->total();
        if ($total > self::MAX_BYTES) {
            throw $this->failure($request, sprintf('слишком большой ответ для загрузки частями: %d байт', $total));
        }

        $etag = $first->header('etag');
        $lastModified = $first->header('last-modified');
        if ($range->end() + 1 < $total && $etag === null && $lastModified === null) {
            // без валидаторов нельзя убедиться, что части — от одной версии ответа
            return $this->transport->send($request);
        }

        $body = $first->body();
        $offset = $range->end() + 1;
        while ($offset < $total) {
            $part = $this->sendPart($request, $offset, $total, $etag, $chunk);
            $requested = sprintf('bytes=%d-%d', $offset, $offset + $chunk - 1);

            if ($part->statusCode() === 412) {
                throw $this->changed($request);
            }
            $partRange = $this->assertPart($request, $part, $offset, $requested, $total);
            if ($part->header('etag') !== $etag || $part->header('last-modified') !== $lastModified) {
                throw $this->changed($request);
            }

            $body .= $part->body();
            $offset = $partRange->end() + 1;
        }

        $headers = $first->headers();
        unset($headers['content-range']);
        $headers['content-length'] = [(string) $total];

        return new HttpResponse(200, $body, $headers);
    }

    private function applies(HttpRequest $request): bool
    {
        if ($request->method() !== 'GET' || $request->header('Range') !== null) {
            return false;
        }
        $host = parse_url($request->url(), PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), $this->hosts, true);
    }

    /**
     * Отправка части (со второй) с повторами. Таймаут уменьшает размер части (по ссылке — и для следующих частей).
     *
     * @throws TransportException
     */
    private function sendPart(HttpRequest $request, int $offset, int $total, string|null $etag, int &$chunk): HttpResponse
    {
        $minimum = min(self::MIN_CHUNK, $this->chunkSize);
        $attempts = $this->retries + 1;
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->transport->send($this->partRequest($request, $offset, $chunk, $etag, true));
            } catch (TimeoutException $e) {
                $last = $e;
                $chunk = max($minimum, intdiv($chunk, 2));
            } catch (TransportException $e) {
                $last = $e;
            }
        }

        assert($last !== null);
        $message = sprintf(
            '%s %s: загрузка частями прервана на байте %d из %d после %d попыток: %s',
            $request->method(),
            $request->maskedUri(),
            $offset,
            $total,
            $attempts,
            $last->getMessage(),
        );

        throw $last instanceof TimeoutException
            ? new TimeoutException($message, $request->method(), $request->maskedUri(), $last->curlErrorCode(), $last)
            : new TransportException($message, $request->method(), $request->maskedUri(), $last->curlErrorCode(), $last);
    }

    private function partRequest(HttpRequest $request, int $offset, int $chunk, ?string $etag, bool $isContinuation): HttpRequest
    {
        $headers = [];
        foreach ($request->headers() as $name => $value) {
            $lower = strtolower($name);
            if ($isContinuation ? in_array($lower, self::PART_EXCLUDED_HEADERS, true) : in_array($lower, ['range', 'connection'], true)) {
                continue;
            }
            $headers[$name] = $value;
        }
        $headers['Range'] = sprintf('bytes=%d-%d', $offset, $offset + $chunk - 1);
        $headers['Connection'] = 'close';
        if ($isContinuation && $etag !== null && !str_starts_with($etag, 'W/')) {
            $headers['If-Match'] = $etag;
        }

        $part = $request->withHeaders($headers);

        return $isContinuation ? $part->withTimeout($this->partTimeout) : $part;
    }

    /**
     * @throws TransportException
     */
    private function assertPart(HttpRequest $request, HttpResponse $part, int $offset, string $requested, ?int $total = null): ContentRange
    {
        $range = $part->statusCode() === 206 ? ContentRange::parse($part->header('content-range') ?? '') : null;
        if ($range !== null && $total !== null && $range->total() !== $total) {
            throw $this->changed($request);
        }

        $encoding = strtolower($part->header('content-encoding') ?? 'identity');
        if ($range === null || $range->start() !== $offset || $encoding !== 'identity' || strlen($part->body()) !== $range->length()) {
            throw $this->failure($request, sprintf('неожиданный ответ на часть %s (HTTP %d)', $requested, $part->statusCode()));
        }

        return $range;
    }

    private function changed(HttpRequest $request): TransportException
    {
        return $this->failure($request, 'ресурс изменился во время загрузки частями');
    }

    private function failure(HttpRequest $request, string $reason): TransportException
    {
        return new TransportException(
            sprintf('%s %s: %s', $request->method(), $request->maskedUri(), $reason),
            $request->method(),
            $request->maskedUri(),
        );
    }
}
