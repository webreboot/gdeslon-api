<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * @implements \IteratorAggregate<int, Offer>
 */
final class SearchResult implements \IteratorAggregate, \Countable
{
    private readonly int $effectiveLimit;

    /**
     * @param list<Offer>  $offers
     * @param list<string> $skipped
     */
    public function __construct(
        private readonly SearchCriteria $criteria,
        private readonly array $offers,
        private readonly ?int $total,
        private readonly array $skipped = [],
        ?int $effectiveLimit = null,
    ) {
        $limit = $criteria->limit();
        $effectiveLimit ??= $limit;
        if ($effectiveLimit < 1 || $effectiveLimit > $limit || $limit % $effectiveLimit !== 0) {
            throw new InvalidArgumentException(sprintf(
                'Фактический размер страницы должен делить limit без остатка: limit %d, получено %d',
                $limit,
                $effectiveLimit,
            ));
        }
        $this->effectiveLimit = $effectiveLimit;
    }

    public function criteria(): SearchCriteria
    {
        return $this->criteria;
    }

    /**
     * @return list<Offer>
     */
    public function offers(): array
    {
        return $this->offers;
    }

    public function count(): int
    {
        return count($this->offers);
    }

    public function isEmpty(): bool
    {
        return $this->offers === [];
    }

    public function total(): ?int
    {
        return $this->total;
    }

    /**
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function effectiveLimit(): int
    {
        return $this->effectiveLimit;
    }

    public function isDegraded(): bool
    {
        return $this->effectiveLimit < $this->criteria->limit();
    }

    public function hasNextPage(): bool
    {
        $seen = $this->criteria->page() * $this->criteria->limit();

        return count($this->offers) + count($this->skipped) >= $this->criteria->limit()
            && $seen < SearchCriteria::MAX_DEPTH
            && ($this->total === null || $seen < $this->total);
    }

    public function nextPage(): ?SearchCriteria
    {
        return $this->hasNextPage() ? $this->criteria->withPage($this->criteria->page() + 1) : null;
    }

    /**
     * @return \ArrayIterator<int, Offer>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->offers);
    }
}
