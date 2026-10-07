<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

/**
 * Текущее время. Порт — чтобы время в тестах было управляемым (сигнатура как у PSR-20 ClockInterface).
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
