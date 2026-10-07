<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

/**
 * Тип заказа (поле `type` API по продажам): товарный заказ или лид (заявка, регистрация).
 */
enum OrderType: int
{
    case Product = 0;
    case Lead = 1;
}
