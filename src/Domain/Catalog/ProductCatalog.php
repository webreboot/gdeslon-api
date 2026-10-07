<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Поиск товарных предложений (офферов).
 */
interface ProductCatalog
{
    /**
     * @throws GdeSlonException
     */
    public function search(SearchCriteria $criteria): SearchResult;
}
