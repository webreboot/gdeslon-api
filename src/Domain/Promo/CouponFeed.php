<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Exception\GdeSlonException;

interface CouponFeed
{
    /**
     * @throws CouponCriteriaRejectedException
     * @throws GdeSlonException
     */
    public function find(CouponCriteria $criteria): CouponList;
}
