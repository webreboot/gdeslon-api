<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Testing;

use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function at(string $time): self
    {
        try {
            return new self(new \DateTimeImmutable($time, new \DateTimeZone('UTC')));
        } catch (\Exception) {
            throw new InvalidArgumentException(sprintf('FrozenClock: неразборное время «%s»', $time));
        }
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
