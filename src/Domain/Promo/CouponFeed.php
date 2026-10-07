<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Купоны и промокоды магазинов вебмастера.
 */
interface CouponFeed
{
    /**
     * @throws CouponCriteriaRejectedException фильтр отклонён API
     * @throws GdeSlonException
     */
    public function find(CouponCriteria $criteria): CouponList;
}
