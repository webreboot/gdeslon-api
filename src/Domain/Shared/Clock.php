<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
