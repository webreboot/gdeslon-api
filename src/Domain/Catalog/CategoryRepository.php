<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Источник категорий товаров. API отдаёт классификатор одним файлом, поэтому порт — один метод.
 */
interface CategoryRepository
{
    /**
     * @throws GdeSlonException
     */
    public function all(): CategoryTree;
}
