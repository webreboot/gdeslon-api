<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

enum LostOrderClaimState: string
{
    case InWork = 'in_work';
    case Closed = 'closed';

    public static function fromApi(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
