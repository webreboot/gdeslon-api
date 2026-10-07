<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class DecimalRate
{
    public static function assert(string $rate, string $context): string
    {
        if (preg_match('/^\d+(\.\d+)?\z/', $rate) !== 1) {
            throw new InvalidArgumentException(sprintf('%s: ставка «%s» — не десятичное число', $context, $rate));
        }

        return $rate;
    }
}
