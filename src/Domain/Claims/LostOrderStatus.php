<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

/**
 * Статус заказа в заявке (`order_status`): ожидает решения рекламодателя, подтверждён или отклонён. Значение — для
 * фильтра API.
 */
enum LostOrderStatus: string
{
    case Waiting = 'waiting';
    case Confirmed = 'confirmed';
    case Declined = 'declined';

    /**
     * Значение из ответа API; в документации ожидание — и `in_waiting` (ответ), и `waiting` (фильтр). null — неизвестно.
     */
    public static function fromApi(string $value): ?self
    {
        $value = strtolower(trim($value));

        return $value === 'in_waiting' ? self::Waiting : self::tryFrom($value);
    }
}
