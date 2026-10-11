<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\ProductCatalog;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
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
        $parts = intdiv($criteria->limit(), $subLimit);
        $firstPage = ($criteria->page() - 1) * $parts + 1;
        $offers = [];
        $skipped = [];
        $total = null;
        for ($part = 1; $part <= $parts; $part++) {
            try {
                $result = $this->inner->search($criteria->withLimit($subLimit)->withPage($firstPage + $part - 1));
            } catch (TimeoutException $e) {
                throw new TimeoutException(
                    sprintf(
                        'Страница limit %d не загрузилась: повтор страницами по %d (запрос %d из %d) тоже превысил время ожидания. %s',
                        $criteria->limit(),
                        $subLimit,
                        $part,
                        $parts,
                        $e->getMessage(),
                    ),
                    $e->method(),
                    $e->url(),
                    $e->curlErrorCode(),
                    $e,
                );
            }

            if ($part === 1) {
                $total = $result->total();
            }
            array_push($offers, ...$result->offers());
            array_push($skipped, ...$result->skipped());
            if (count($result) + count($result->skipped()) < $subLimit) {
                break;
            }
        }

        return new SearchResult($criteria, $offers, $total, $skipped, $subLimit);
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
