<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Clock;

use Webreboot\GdeSlon\Domain\Shared\Clock;

/**
 * Системное время в UTC.
 *
 * @internal
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
