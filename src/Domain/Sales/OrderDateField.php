<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

enum OrderDateField: string
{
    case Transition = 'transition_at';
    case Created = 'created_at';
    case LastUpdated = 'last_updated_at';
    case Confirmed = 'confirmed_at';
    case Accrued = 'accrued_at';
}
