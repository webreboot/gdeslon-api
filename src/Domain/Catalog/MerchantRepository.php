<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\GdeSlonException;

interface MerchantRepository
{
    /**
     * @throws GdeSlonException
     */
    public function all(): MerchantList;
}
