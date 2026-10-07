<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Json;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Json\JsonDecimal;

final class JsonDecimalTest extends TestCase
{
    public function testShortestExactDecimal(): void
    {
        foreach (['-1', '17'] as $precision) {
            $previous = ini_get('serialize_precision');
            ini_set('serialize_precision', $precision);
            try {
                $actual = array_map(
                    static fn (float $value): ?string => JsonDecimal::fromFloat($value),
                    [99.9, 0.1, 1500.0, 1.0E-7, 0.0, 0.05 + 0.01, 999999999999999.9],
                );
            } finally {
                ini_set('serialize_precision', (string) $previous);
            }

            self::assertSame(['99.9', '0.1', '1500', '0.0000001', '0', '0.060000000000000005', '999999999999999.9'], $actual, 'serialize_precision=' . $precision);
        }
    }

    public function testNotAmounts(): void
    {
        foreach ([-1.0, INF, NAN, 1.0E+15] as $value) {
            self::assertNull(JsonDecimal::fromFloat($value));
        }
    }
}
