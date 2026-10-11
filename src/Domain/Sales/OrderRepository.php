<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

use Webreboot\GdeSlon\Exception\GdeSlonException;

interface OrderRepository
{
    /**
     * @throws GdeSlonException
     */
    public function find(#[\SensitiveParameter] OrderCriteria $criteria): OrderList;
}
