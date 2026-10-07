<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

/**
 * Статус заказа (поле `state` API по продажам, docs/gdeslon-api/orders.md).
 *
 * Новый (в кабинете — «потенциальный», не подтверждён) → отменён / отложен / подтверждён → выплачен. Изменение статуса
 * попадает в API только на следующий день.
 */
enum OrderState: int
{
    case New = 0;
    case Cancelled = 1;
    case Pending = 2;
    case Confirmed = 3;
    /** Выплачен вебмастеру. */
    case Paid = 4;
}
