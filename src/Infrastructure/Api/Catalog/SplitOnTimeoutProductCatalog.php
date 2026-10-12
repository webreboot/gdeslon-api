<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\ProductCatalog;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;

/** @internal */
final class SplitOnTimeoutProductCatalog implements ProductCatalog
{
    private const DEFAULT_FALLBACK_LIMIT = 5;

    private const MAX_REQUESTS = 20;

    private const HINT = 'Если ответ поиска обрывается после ~16 КБ, уменьшите limit (сейчас %d)';

    public function __construct(
        private readonly ProductCatalog $inner,
        private readonly ?int $fallbackLimit = self::DEFAULT_FALLBACK_LIMIT,
    ) {
        self::assertFallbackLimit($fallbackLimit);
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        try {
            return $this->inner->search($criteria);
        } catch (ConnectTimeoutException $e) {
            throw $e;
        } catch (TimeoutException $e) {
            $limit = $criteria->limit();
            if ($this->fallbackLimit === null || $limit <= $this->fallbackLimit) {
                throw self::withHint($e, sprintf(self::HINT, $limit));
            }

            $subLimit = self::largestDivisor($limit, $this->fallbackLimit);
            if (intdiv($limit, $subLimit) > self::MAX_REQUESTS) {
                throw self::withHint($e, sprintf(
                    self::HINT . ' или выберите кратный %d (до %d) — тогда страница загрузится частями по %d',
                    $limit,
                    $this->fallbackLimit,
                    min(self::MAX_REQUESTS * $this->fallbackLimit, SearchCriteria::MAX_LIMIT),
                    $this->fallbackLimit,
                ));
            }

            return $this->searchInParts($criteria, $subLimit);
        }
    }

    private function searchInParts(SearchCriteria $criteria, int $subLimit): SearchResult
    {
        $queue = self::pieces($subLimit, $criteria->limit(), $criteria->page());
        $sent = 0;
        $offers = [];
        $skipped = [];
        $total = null;
        $loaded = false;
        $smallest = $subLimit;
        while ($queue !== []) {
            [$size, $page] = array_shift($queue);
            $sent++;
            try {
                $result = $this->inner->search($criteria->withLimit($size)->withPage($page));
            } catch (ConnectTimeoutException $e) {
                throw $e;
            } catch (TimeoutException $e) {
                $queue = [...self::split($criteria, $size, $page, $sent, count($queue), $e), ...$queue];

                continue;
            }

            if (!$loaded) {
                $total = $result->total();
                $loaded = true;
            }
            $smallest = min($smallest, $size);
            array_push($offers, ...$result->offers());
            array_push($skipped, ...$result->skipped());
            if (count($result) + count($result->skipped()) < $size) {
                break;
            }
        }

        return new SearchResult($criteria, $offers, $total, $skipped, $smallest);
    }

    /**
     * @return list<array{int, int}>
     */
    private static function split(SearchCriteria $criteria, int $size, int $page, int $sent, int $queued, TimeoutException $e): array
    {
        $first = ($page - 1) * $size + 1;
        $part = sprintf(
            'часть l=%d&p=%d (%s) превысила время ожидания',
            $size,
            $page,
            $size === 1 ? sprintf('оффер %d', $first) : sprintf('офферы %d–%d', $first, $page * $size),
        );
        if ($size === 1) {
            throw self::pageFailed($criteria, $e, sprintf('%s, делить её дальше некуда (под-запросов: %d из %d)', $part, $sent, self::MAX_REQUESTS));
        }

        $pieceSize = self::largestDivisor($size, intdiv($size, 2));
        $pieces = self::pieces($pieceSize, $size, $page);
        $left = self::MAX_REQUESTS - $sent - $queued;
        if (count($pieces) > $left) {
            throw self::pageFailed($criteria, $e, sprintf(
                '%s, а на догрузку частями по %d не хватает под-запросов (нужно ещё %d, осталось %d из %d)',
                $part,
                $pieceSize,
                count($pieces),
                $left,
                self::MAX_REQUESTS,
            ));
        }

        return $pieces;
    }

    /**
     * @return list<array{int, int}>
     */
    private static function pieces(int $pieceSize, int $size, int $page): array
    {
        $count = intdiv($size, $pieceSize);
        $first = ($page - 1) * $count + 1;

        return array_map(static fn (int $k): array => [$pieceSize, $first + $k], range(0, $count - 1));
    }

    private static function pageFailed(SearchCriteria $criteria, TimeoutException $e, string $reason): TimeoutException
    {
        return new TimeoutException(
            sprintf('Страница limit %d не загрузилась: %s. %s', $criteria->limit(), $reason, $e->getMessage()),
            $e->method(),
            $e->url(),
            $e->curlErrorCode(),
            $e,
        );
    }

    private static function largestDivisor(int $limit, int $max): int
    {
        for ($divisor = $max; $divisor > 1; $divisor--) {
            if ($limit % $divisor === 0) {
                return $divisor;
            }
        }

        return 1;
    }

    private static function assertFallbackLimit(?int $fallbackLimit): void
    {
        if ($fallbackLimit !== null && ($fallbackLimit < 1 || $fallbackLimit > SearchCriteria::MAX_LIMIT)) {
            throw new InvalidArgumentException(sprintf(
                'searchFallbackLimit должен быть от 1 до %d или null, получено %d',
                SearchCriteria::MAX_LIMIT,
                $fallbackLimit,
            ));
        }
    }

    private static function withHint(TimeoutException $e, string $hint): TimeoutException
    {
        return new TimeoutException(
            sprintf('%s. %s', rtrim($e->getMessage(), '.'), $hint),
            $e->method(),
            $e->url(),
            $e->curlErrorCode(),
            $e,
        );
    }
}
