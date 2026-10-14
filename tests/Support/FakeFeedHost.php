<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;
use Webreboot\GdeSlon\Infrastructure\Feed\PartFetcher;
use Webreboot\GdeSlon\Infrastructure\Feed\PartResponse;

final class FakeFeedHost implements PartFetcher
{
    public const LAST_MODIFIED = 'Wed, 14 Oct 2026 09:48:14 GMT';

    /** @var list<array{range: array{int, int}, headers: array<string, string>, url: string}> */
    private array $requests = [];

    /** @var list<int> */
    private array $batches = [];

    /** @var array<int, list<TransportException|int>> */
    private array $failures = [];

    /** @var array<int, PartResponse> */
    private array $overrides = [];

    private ?string $nextContent = null;

    private ?int $changeAfter = null;

    private bool $ignoreRange = false;

    private int $limit;

    public function __construct(
        private string $content,
        private string $etag = '"6acf4fde-272ead2"',
        int $limit = 16384,
        private readonly bool $lastModified = true,
    ) {
        $this->limit = $limit;
    }

    public function failAt(int $offset, TransportException|int $failure, int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[$offset][] = $failure;
        }

        return $this;
    }

    public function changeAfter(int $requests, string $content, string $etag): self
    {
        $this->changeAfter = $requests;
        $this->nextContent = $content;
        $this->nextEtag = $etag;

        return $this;
    }

    private string $nextEtag = '';

    public function ignoreRange(): self
    {
        $this->ignoreRange = true;

        return $this;
    }

    public function override(int $requestNumber, PartResponse $response): self
    {
        $this->overrides[$requestNumber] = $response;

        return $this;
    }

    public function fetch(#[\SensitiveParameter] FeedUrl $url, array $ranges, array $headers): array
    {
        $this->batches[] = count($ranges);
        $results = [];
        foreach ($ranges as $range) {
            $this->requests[] = ['range' => $range, 'headers' => $headers, 'url' => $url->value()];
            $results[] = $this->respond($range, $headers);
        }

        return $results;
    }

    /**
     * @param array{int, int}       $range
     * @param array<string, string> $headers
     */
    private function respond(array $range, array $headers): PartResponse|TransportException
    {
        $number = count($this->requests);
        if ($this->changeAfter !== null && $number > $this->changeAfter && $this->nextContent !== null) {
            $this->content = $this->nextContent;
            $this->etag = $this->nextEtag;
            $this->nextContent = null;
        }
        if (isset($this->overrides[$number])) {
            return $this->overrides[$number];
        }
        if (($this->failures[$range[0]] ?? []) !== []) {
            $failure = array_shift($this->failures[$range[0]]);

            return is_int($failure) ? new PartResponse($failure, [], 'error ' . $failure) : $failure;
        }

        $total = strlen($this->content);
        $common = array_filter(['ETag' => $this->etag, 'Last-Modified' => $this->lastModified ? self::LAST_MODIFIED : ''], static fn (string $value): bool => $value !== '');
        $inm = $headers['If-None-Match'] ?? null;
        if (($inm !== null && $inm === $this->etag) || (($headers['If-Modified-Since'] ?? null) === self::LAST_MODIFIED && $inm === null)) {
            return new PartResponse(304, $common, '');
        }
        $ifRange = $headers['If-Range'] ?? null;
        if ($this->ignoreRange || ($ifRange !== null && $ifRange !== $this->etag && $ifRange !== self::LAST_MODIFIED)) {
            return new PartResponse(200, $common + ['Content-Length' => (string) $total], substr($this->content, 0, $this->limit), $total > $this->limit);
        }
        if ($range[0] >= $total) {
            return new PartResponse(416, ['Content-Range' => 'bytes */' . $total], '');
        }
        $end = min($range[1], $total - 1);

        return new PartResponse(206, $common + ['Content-Range' => sprintf('bytes %d-%d/%d', $range[0], $end, $total)], substr($this->content, $range[0], $end - $range[0] + 1));
    }

    /**
     * @return list<array{range: array{int, int}, headers: array<string, string>, url: string}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<int>
     */
    public function batches(): array
    {
        return $this->batches;
    }

    public static function timeout(): TimeoutException
    {
        return new TimeoutException('GET https://export.gdeslon.ru/uploads/exports/***: превышено время ожидания (cURL 28)', 'GET', 'https://export.gdeslon.ru/uploads/exports/***', 28);
    }
}
