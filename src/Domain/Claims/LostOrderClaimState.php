<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

/**
 * Состояние заявки (`ticket_state`): в работе или закрыта.
 */
enum LostOrderClaimState: string
{
    case InWork = 'in_work';
    case Closed = 'closed';

    /**
     * Значение из ответа API; null — неизвестно.
     */
    public static function fromApi(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
