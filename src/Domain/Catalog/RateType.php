<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

enum RateType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
