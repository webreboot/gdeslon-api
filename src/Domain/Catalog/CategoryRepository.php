<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\GdeSlonException;

interface CategoryRepository
{
    /**
     * @throws GdeSlonException
     */
    public function all(): CategoryTree;
}
