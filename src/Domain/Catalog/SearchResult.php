<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

/**
 * Страница результатов поиска.
 *
 * @implements \IteratorAggregate<int, Offer>
 */
final class SearchResult implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Offer>  $offers  офферы страницы в порядке API (ID могут повторяться)
     * @param int|null     $total   сколько всего найдено; null — неизвестно (API сообщает «≥ 1 000 000»)
     * @param list<string> $skipped почему записи страницы не вошли в результат (битые данные отдельных офферов)
     */
    public function __construct(
        private readonly SearchCriteria $criteria,
        private readonly array $offers,
        private readonly ?int $total,
        private readonly array $skipped = [],
    ) {
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

    /**
     * Есть ли следующая страница: текущая заполнена, не достигнут известный итог и окно API (page × limit ≤ 10 000).
     */
    public function hasNextPage(): bool
    {
        $seen = $this->criteria->page() * $this->criteria->limit();

        return count($this->offers) + count($this->skipped) >= $this->criteria->limit()
            && $seen < SearchCriteria::MAX_DEPTH
            && ($this->total === null || $seen < $this->total);
    }

    /**
     * Критерии следующей страницы или null, если её нет.
     */
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
