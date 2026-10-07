<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

/**
 * Сортировка выдачи поиска (параметр `order` search.xml).
 */
enum OfferSort: string
{
    /** По цене — по убыванию (дорогие первыми; проверено частично, см. docs/gdeslon-api/search.md). */
    case Price = 'price';
    case PartnerBenefit = 'partner_benefit';
    case Newest = 'newest';
}
