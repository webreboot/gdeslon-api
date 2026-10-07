<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

enum LostOrderStatus: string
{
    case Waiting = 'waiting';
    case Confirmed = 'confirmed';
    case Declined = 'declined';

    public static function fromApi(string $value): ?self
    {
        $value = strtolower(trim($value));

        return $value === 'in_waiting' ? self::Waiting : self::tryFrom($value);
    }
}
