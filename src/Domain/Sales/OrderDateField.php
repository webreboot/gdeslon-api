<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

/**
 * По какой дате заказа выбирать период (значения — имена полей API по продажам).
 */
enum OrderDateField: string
{
    /** Переход по партнёрской ссылке. */
    case Transition = 'transition_at';
    case Created = 'created_at';
    /** Последнее изменение — для синхронизации статусов. */
    case LastUpdated = 'last_updated_at';
    case Confirmed = 'confirmed_at';
    /** Начисление (выплата) вебмастеру. */
    case Accrued = 'accrued_at';
}
