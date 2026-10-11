<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

enum DataOrigin: string
{
    case Fetched = 'fetched';
    case Cached = 'cached';
    case Revalidated = 'revalidated';
    case Stale = 'stale';

    public function isCurrent(): bool
    {
        return $this !== self::Stale;
    }
}
