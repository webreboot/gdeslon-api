<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

enum OrderType: int
{
    case Product = 0;
    case Lead = 1;
}
