<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Json;

/** @internal */
final class JsonDecimal
{
    public static function fromFloat(float $value): ?string
    {
        if (!is_finite($value) || $value < 0 || $value >= 1e15) {
            return null;
        }
        $maxPrecision = 17 + ($value > 0 && $value < 1 ? (int) -floor(log10($value)) : 0);
        for ($precision = 0; $precision <= $maxPrecision; $precision++) {
            $decimal = sprintf('%.' . $precision . 'F', $value);
            if ((float) $decimal === $value) {
                return $decimal;
            }
        }

        return null;
    }
}
