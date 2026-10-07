<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Domain\Shared\Clock;

final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public static function at(string $time): self
    {
        return new self(new \DateTimeImmutable($time, new \DateTimeZone('UTC')));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
