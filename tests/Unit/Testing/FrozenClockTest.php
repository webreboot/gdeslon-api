<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Testing\FrozenClock;

final class FrozenClockTest extends TestCase
{
    public function testAtWithoutZoneIsUtc(): void
    {
        $now = FrozenClock::at('2026-10-07 10:00:00')->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertSame('2026-10-07T10:00:00+00:00', $now->format(DATE_ATOM));
    }

    public function testAtKeepsExplicitOffset(): void
    {
        $now = FrozenClock::at('2026-10-07T10:00:00+03:00')->now();

        self::assertSame('+03:00', $now->format('P'));
        self::assertSame((new \DateTimeImmutable('2026-10-07T07:00:00Z'))->getTimestamp(), $now->getTimestamp());
    }

    public function testNowIsFrozen(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2001-02-03T04:05:06Z'));

        self::assertSame('2001-02-03T04:05:06+00:00', $clock->now()->format(DATE_ATOM));
        self::assertEquals($clock->now(), $clock->now());
    }

    public function testAdvance(): void
    {
        $clock = FrozenClock::at('2026-10-07T10:00:00Z');
        $before = $clock->now();

        $clock->advance(3600);
        self::assertSame('2026-10-07T11:00:00+00:00', $clock->now()->format(DATE_ATOM));

        $clock->advance(-90);
        self::assertSame('2026-10-07T10:58:30+00:00', $clock->now()->format(DATE_ATOM));
        self::assertSame('2026-10-07T10:00:00+00:00', $before->format(DATE_ATOM));
    }

    public function testInvalidTimeIsRejected(): void
    {
        try {
            FrozenClock::at('не время');
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('не время', $e->getMessage());
        }
    }
}
