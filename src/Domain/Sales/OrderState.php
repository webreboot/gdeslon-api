<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

enum OrderState: int
{
    case New = 0;
    case Cancelled = 1;
    case Pending = 2;
    case Confirmed = 3;
    case Paid = 4;
}
