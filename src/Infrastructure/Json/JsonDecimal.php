<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Json;

/**
 * Сумма, пришедшая JSON-числом, → десятичная строка.
 *
 * @internal
 */
final class JsonDecimal
{
    /**
     * Кратчайшая десятичная запись float с точным обратным преобразованием — без зависимости от ini serialize_precision
     * (при 17 json_encode(99.9) даёт «99.900000000000006»). Отрицательные, нечисла и ≥ 1e15 (за пределом точности
     * float для сумм) — null.
     */
    public static function fromFloat(float $value): ?string
    {
        if (!is_finite($value) || $value < 0 || $value >= 1e15) {
            return null;
        }
        // 17 значащих цифр всегда дают точную запись double; у значений < 1 к ним добавляются ведущие нули дробной части
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
