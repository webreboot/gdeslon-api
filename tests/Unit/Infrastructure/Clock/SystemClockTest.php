<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Clock;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Clock\SystemClock;

final class SystemClockTest extends TestCase
{
    public function testCurrentTimeInUtc(): void
    {
        $before = time();
        $now = (new SystemClock())->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual(time(), $now->getTimestamp());
    }
}
