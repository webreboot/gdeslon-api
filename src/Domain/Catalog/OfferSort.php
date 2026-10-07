<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

enum OfferSort: string
{
    case Price = 'price';
    case PartnerBenefit = 'partner_benefit';
    case Newest = 'newest';
}
