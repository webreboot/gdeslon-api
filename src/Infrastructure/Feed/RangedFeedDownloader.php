<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\FileException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Exception\UnsupportedFormatException;
use Webreboot\GdeSlon\Infrastructure\Http\ContentRange;
use Webreboot\GdeSlon\Infrastructure\Http\SecretMasker;
use Webreboot\GdeSlon\Infrastructure\Yml\ZipEntryExtractor;

final class RangedFeedDownloader implements FeedDownloader
{
    public const DEFAULT_CHUNK_SIZE = 16384;

    public const DEFAULT_CONCURRENCY = 8;

    public const DEFAULT_PART_RETRIES = 3;

    public const DEFAULT_MAX_BYTES = 2 * 1024 * 1024 * 1024;

    private const MIN_CHUNK_SIZE = 1024;

    private const MAX_CONCURRENCY = 64;

    private const MAX_PART_RETRIES = 10;

    private const RETRYABLE_STATUSES = [420, 429, 500, 502, 503, 504];

    // временные сбои: COULDNT_RESOLVE_HOST, COULDNT_CONNECT, PARTIAL_FILE, SSL_CONNECT_ERROR, GOT_NOTHING, SEND/RECV_ERROR;
    // ошибки сертификата (51, 58, 60, 77) повтор не исправит
    private const RETRYABLE_CURL_CODES = [6, 7, 18, 35, 52, 55, 56];

    private const DEFLATED = 8;

    private const BACKOFF_MICROSECONDS = 500_000;

    private const SNIPPET_BYTES = 200;

    private readonly ?\Closure $sleep;

    /**
     * @internal
     *
     * @param (\Closure(int): void)|null $sleep
     */
    public function __construct(
        private readonly PartFetcher $fetcher,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private readonly int $concurrency = self::DEFAULT_CONCURRENCY,
        private readonly int $partRetries = self::DEFAULT_PART_RETRIES,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly ZipEntryExtractor $extractor = new ZipEntryExtractor(),
        ?\Closure $sleep = null,
    ) {
        if ($chunkSize < self::MIN_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf('Размер части выгрузки должен быть не меньше %d байт, получено %d', self::MIN_CHUNK_SIZE, $chunkSize));
        }
        if ($concurrency < 1 || $concurrency > self::MAX_CONCURRENCY) {
            throw new InvalidArgumentException(sprintf('Число параллельных частей должно быть от 1 до %d, получено %d', self::MAX_CONCURRENCY, $concurrency));
        }
        if ($partRetries < 0 || $partRetries > self::MAX_PART_RETRIES) {
            throw new InvalidArgumentException(sprintf('Число повторов части должно быть от 0 до %d, получено %d', self::MAX_PART_RETRIES, $partRetries));
        }
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Лимит размера выгрузки должен быть положительным');
        }
        $this->sleep = $sleep;
    }

    public static function create(
        #[\SensitiveParameter]
        Config $config = new Config(),
        int $concurrency = self::DEFAULT_CONCURRENCY,
        int $partRetries = self::DEFAULT_PART_RETRIES,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): self {
        return new self(
            CurlPartFetcher::fromConfig($config),
            $config->rangeChunkSize() ?? self::DEFAULT_CHUNK_SIZE,
            $concurrency,
            $partRetries,
            $maxBytes,
        );
    }

    public function download(#[\SensitiveParameter] FeedUrl $url, string $target, ?FeedValidators $previous = null): FeedDownload
    {
        self::assertTarget($target);
        $lockPath = $target . '.zip.part.lock';
        $lock = self::lock($lockPath);

        try {
            try {
                return $this->attempt($url, $target, $previous);
            } catch (FeedChangedException) {
                self::discard($target);
            }

            try {
                return $this->attempt($url, $target, $previous);
            } catch (FeedChangedException) {
                self::discard($target);

                throw self::invalid($url, 'выгрузка менялась во время загрузки дважды подряд, повторите позже');
            }
        } finally {
            @unlink($lockPath);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return resource
     */
    private static function lock(string $path)
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $lock = @fopen($path, 'c');
            if ($lock === false) {
                throw new FileException('Не удалось создать файл блокировки загрузки выгрузки');
            }
            if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                fclose($lock);

                throw new FileException($wouldBlock === 1
                    ? 'Эта выгрузка уже загружается другим процессом в тот же файл'
                    : 'Файловая система не поддерживает блокировку файлов: загрузка выгрузки невозможна');
            }
            clearstatcache(true, $path);
            $held = fstat($lock);
            $current = @stat($path);
            // предыдущий владелец удаляет файл блокировки до снятия: блокировка на удалённом файле ничего не защищает
            if ($held !== false && $current !== false && $held['ino'] === $current['ino'] && $held['dev'] === $current['dev']) {
                return $lock;
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        throw new FileException('Эта выгрузка уже загружается другим процессом в тот же файл');
    }

    private function attempt(#[\SensitiveParameter] FeedUrl $url, string $target, ?FeedValidators $previous): FeedDownload
    {
        $conditional = [];
        if ($previous !== null && is_file($target)) {
            $conditional = array_filter(['If-None-Match' => $previous->etag(), 'If-Modified-Since' => $previous->lastModified()], static fn (?string $value): bool => $value !== null);
        }

        $first = $this->first($url, $conditional);
        if ($first->status() === 304) {
            return new FeedDownload($target, FeedDownloadStatus::NotModified, self::size($target), new FeedValidators(
                $first->header('etag') ?? $previous?->etag(),
                $first->header('last-modified') ?? $previous?->lastModified(),
            ));
        }
        if ($first->status() === 200 && $first->isTruncated()) {
            throw self::invalid($url, 'хост не поддерживает Range: файл отдаётся только целиком, а загрузка частями невозможна');
        }

        $body = $first->body();
        $total = $first->status() === 200 ? strlen($body) : (int) ContentRange::parse((string) $first->header('content-range'))?->total();
        if ($total > $this->maxBytes) {
            throw self::invalid($url, sprintf('выгрузка (%d байт) больше лимита %d байт', $total, $this->maxBytes));
        }
        $zip = self::kind($body) ?? throw self::invalid($url, sprintf('ответ — не выгрузка: ожидался zip или YML (%s)', (string) $first->header('content-type')));
        if ($zip && !$this->extractor->canInflate() && strlen($body) >= 10 && (unpack('v', $body, 8) ?: [1 => 0])[1] === self::DEFLATED) {
            throw new UnsupportedFormatException('Для распаковки выгрузки нужно расширение zlib');
        }
        $validators = new FeedValidators($first->header('etag'), $first->header('last-modified'));

        $part = $target . '.zip.part';
        $offset = $this->fetchRest($url, $part, self::prepare($part, $body, $validators, $total), $total, $validators);
        if ($offset !== $total || self::size($part) !== $total) {
            throw self::invalid($url, sprintf('скачано %d байт из %d', self::size($part), $total));
        }

        return $this->finish($part, $target, $zip, $validators);
    }

    /**
     * @param array<string, string> $headers
     */
    private function first(#[\SensitiveParameter] FeedUrl $url, array $headers): PartResponse
    {
        $range = [0, $this->chunkSize - 1];
        for ($attempt = 0; ; $attempt++) {
            $result = $this->fetcher->fetch($url, [$range], $headers)[0] ?? self::invalid($url, 'нет ответа на запрос части');
            $error = match (true) {
                !$result instanceof PartResponse => $result,
                in_array($result->status(), [200, 304], true) => null,
                $result->status() === 206 => self::checkFirst($url, $result),
                in_array($result->status(), self::RETRYABLE_STATUSES, true) => self::httpError($url, $result),
                default => throw self::httpError($url, $result),
            };
            if ($error === null && $result instanceof PartResponse) {
                return $result;
            }
            $this->pauseOrThrow($attempt, $error ?? self::invalid($url, 'нет ответа на запрос части'));
        }
    }

    private static function checkFirst(#[\SensitiveParameter] FeedUrl $url, #[\SensitiveParameter] PartResponse $response): ?GdeSlonException
    {
        $range = ContentRange::parse((string) $response->header('content-range'));
        if ($range === null || $range->start() !== 0) {
            throw self::invalid($url, 'неверный Content-Range первой части');
        }

        return strlen($response->body()) === $range->length() ? null : self::invalid($url, 'часть выгрузки оборвана');
    }

    private function fetchRest(#[\SensitiveParameter] FeedUrl $url, string $part, int $offset, int $total, FeedValidators $validators): int
    {
        if ($offset >= $total) {
            return $offset;
        }
        $headers = self::ifRange($validators);
        $handle = @fopen($part, 'ab');
        if ($handle === false) {
            throw new FileException('Не удалось открыть файл для загрузки выгрузки');
        }

        try {
            while ($offset < $total) {
                $ranges = [];
                for ($start = $offset; $start < $total && count($ranges) < $this->concurrency; $start += $this->chunkSize) {
                    $ranges[] = [$start, min($start + $this->chunkSize, $total) - 1];
                }
                $results = $this->fetcher->fetch($url, $ranges, $headers);
                foreach ($ranges as $index => $range) {
                    $body = $this->partBody($url, $range, $results[$index] ?? null, $headers, $validators, $total);
                    if (@fwrite($handle, $body) !== strlen($body)) {
                        throw new FileException('Не удалось записать часть выгрузки');
                    }
                    $offset += strlen($body);
                }
            }
        } finally {
            fclose($handle);
        }

        return $offset;
    }

    /**
     * @param array{int, int}       $range
     * @param array<string, string> $headers
     */
    private function partBody(#[\SensitiveParameter] FeedUrl $url, array $range, #[\SensitiveParameter] PartResponse|TransportException|null $result, array $headers, FeedValidators $validators, int $total): string
    {
        for ($attempt = 0; ; $attempt++) {
            $outcome = self::checkPart($url, $range, $result, $validators, $total);
            if (is_string($outcome)) {
                return $outcome;
            }
            $this->pauseOrThrow($attempt, $outcome);
            $result = $this->fetcher->fetch($url, [$range], $headers)[0] ?? null;
        }
    }

    /**
     * @param array{int, int} $range
     */
    private static function checkPart(#[\SensitiveParameter] FeedUrl $url, array $range, #[\SensitiveParameter] PartResponse|TransportException|null $result, FeedValidators $validators, int $total): string|GdeSlonException
    {
        if (!$result instanceof PartResponse) {
            return $result ?? self::invalid($url, 'нет ответа на запрос части');
        }
        $status = $result->status();
        if (in_array($status, self::RETRYABLE_STATUSES, true)) {
            return self::httpError($url, $result);
        }
        if ($status === 200 || $status === 416) {
            throw new FeedChangedException();
        }
        if ($status !== 206) {
            throw self::httpError($url, $result);
        }
        $etag = $result->header('etag');
        if ($etag !== null && $validators->etag() !== null && $etag !== $validators->etag()) {
            throw new FeedChangedException();
        }
        $contentRange = ContentRange::parse((string) $result->header('content-range'));
        if ($contentRange !== null && $contentRange->total() !== $total) {
            throw new FeedChangedException();
        }
        if ($contentRange === null || $contentRange->start() !== $range[0] || $contentRange->end() !== $range[1]) {
            throw self::invalid($url, sprintf('Content-Range ответа не совпадает с запрошенной частью %d-%d', $range[0], $range[1]));
        }

        return strlen($result->body()) === $contentRange->length() ? $result->body() : self::invalid($url, 'часть выгрузки оборвана');
    }

    private function pauseOrThrow(int $attempt, GdeSlonException $error): void
    {
        $retryable = !$error instanceof TransportException
            || $error instanceof TimeoutException
            || in_array($error->curlErrorCode(), self::RETRYABLE_CURL_CODES, true);
        if (!$retryable || $attempt >= $this->partRetries) {
            throw $error;
        }
        $pause = self::BACKOFF_MICROSECONDS * ($attempt + 1);
        if ($this->sleep === null) {
            usleep($pause);
        } else {
            ($this->sleep)($pause);
        }
    }

    private function finish(string $part, string $target, bool $zip, FeedValidators $validators): FeedDownload
    {
        try {
            if ($zip) {
                $bytes = $this->extractor->extract($part, $target);
                @unlink($part);
            } else {
                if (!@rename($part, $target)) {
                    throw new FileException('Не удалось заменить файл выгрузки скачанным (файл открыт другой программой?)');
                }
                $bytes = self::size($target);
            }
        } catch (UnexpectedResponseException|UnsupportedFormatException $e) {
            self::discard($target);

            throw $e;
        }
        @unlink(self::statePath($part));

        return new FeedDownload($target, FeedDownloadStatus::Downloaded, $bytes, $validators);
    }

    private static function prepare(string $part, #[\SensitiveParameter] string $first, FeedValidators $validators, int $total): int
    {
        $state = ['etag' => $validators->etag(), 'last_modified' => $validators->lastModified(), 'total' => $total];
        if (!$validators->isEmpty() && is_file($part) && self::readState(self::statePath($part)) === $state) {
            $size = self::size($part);
            if ($size >= strlen($first) && $size <= $total && file_get_contents($part, false, null, 0, strlen($first)) === $first) {
                return $size;
            }
        }

        @unlink(self::statePath($part));
        if (@file_put_contents($part, $first) !== strlen($first)) {
            throw new FileException('Не удалось записать часть выгрузки');
        }
        if (!$validators->isEmpty()) {
            @file_put_contents(self::statePath($part), json_encode($state, JSON_THROW_ON_ERROR));
        }

        return strlen($first);
    }

    /**
     * @return array<mixed>|null
     */
    private static function readState(string $path): ?array
    {
        $json = is_file($path) ? @file_get_contents($path) : false;
        if ($json === false) {
            return null;
        }
        $state = json_decode($json, true);

        return is_array($state) ? $state : null;
    }

    private static function size(string $path): int
    {
        clearstatcache(true, $path);

        return (int) @filesize($path);
    }

    private static function statePath(string $part): string
    {
        return $part . '.json';
    }

    private static function discard(string $target): void
    {
        @unlink($target . '.zip.part');
        @unlink(self::statePath($target . '.zip.part'));
    }

    /**
     * @return array<string, string>
     */
    private static function ifRange(FeedValidators $validators): array
    {
        $etag = $validators->etag();
        if ($etag !== null && !str_starts_with($etag, 'W/')) {
            return ['If-Range' => $etag];
        }

        return $validators->lastModified() === null ? [] : ['If-Range' => $validators->lastModified()];
    }

    private static function kind(#[\SensitiveParameter] string $body): ?bool
    {
        if (str_starts_with($body, "PK\x03\x04")) {
            return true;
        }
        $text = ltrim(str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body);

        return str_starts_with($text, '<?xml') || str_starts_with($text, '<yml_catalog') ? false : null;
    }

    private static function assertTarget(string $target): void
    {
        if (str_contains($target, '://') || trim($target) === '') {
            throw new InvalidArgumentException('Файл выгрузки: ожидался путь к локальному файлу');
        }
        $directory = realpath(dirname($target));
        if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
            throw new FileException('Каталог для выгрузки не существует или недоступен для записи');
        }
    }

    private static function httpError(#[\SensitiveParameter] FeedUrl $url, #[\SensitiveParameter] PartResponse $response): HttpException
    {
        $status = $response->status();
        $message = sprintf('GET %s: HTTP %d', $url->masked(), $status);
        $snippet = SecretMasker::maskValues(self::snippet($response->body()), $url->secrets());

        return in_array($status, [401, 403], true)
            ? new AuthenticationException($message, $status, 'GET', $url->masked(), $snippet)
            : new HttpException($message, $status, 'GET', $url->masked(), $snippet);
    }

    private static function snippet(#[\SensitiveParameter] string $body): string
    {
        $snippet = substr($body, 0, self::SNIPPET_BYTES);
        for ($i = 0; $i < 3 && preg_match('//u', $snippet) !== 1; $i++) {
            $snippet = substr($snippet, 0, -1);
        }

        return preg_match('//u', $snippet) === 1 ? $snippet : '';
    }

    private static function invalid(#[\SensitiveParameter] FeedUrl $url, string $reason): UnexpectedResponseException
    {
        return new UnexpectedResponseException(sprintf('GET %s: %s', $url->masked(), $reason));
    }
}
