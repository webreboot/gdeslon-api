<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Источник магазинов. API отдаёт весь список одним документом, поэтому порт — один метод.
 */
interface MerchantRepository
{
    /**
     * @throws GdeSlonException
     */
    public function all(): MerchantList;
}
