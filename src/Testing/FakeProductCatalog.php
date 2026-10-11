<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Testing;

use Webreboot\GdeSlon\Domain\Catalog\ProductCatalog;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Exception\GdeSlonException;

final class FakeProductCatalog implements ProductCatalog
{
    /** @var list<SearchCriteria> */
    private array $criteria = [];

    /**
     * @param list<\Closure(SearchCriteria): SearchResult> $pages
     */
    public function __construct(private array $pages)
    {
    }

    /**
     * @throws GdeSlonException
     * @throws \LogicException
     */
    public function search(SearchCriteria $criteria): SearchResult
    {
        $this->criteria[] = $criteria;
        $page = array_shift($this->pages) ?? throw new \LogicException('FakeProductCatalog: неожиданный поиск');

        return $page($criteria);
    }

    /**
     * @return list<SearchCriteria>
     */
    public function criteria(): array
    {
        return $this->criteria;
    }
}
