<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

/**
 * @internal
 */
final class Decimal
{
    private function __construct()
    {
    }

    public static function add(string $left, string $right): string
    {
        [$leftInteger, $leftFraction] = self::split($left);
        [$rightInteger, $rightFraction] = self::split($right);
        $scale = max(strlen($leftFraction), strlen($rightFraction));
        $width = max(strlen($leftInteger), strlen($rightInteger));
        $leftDigits = str_pad($leftInteger, $width, '0', STR_PAD_LEFT) . str_pad($leftFraction, $scale, '0');
        $rightDigits = str_pad($rightInteger, $width, '0', STR_PAD_LEFT) . str_pad($rightFraction, $scale, '0');

        $digits = $leftDigits;
        $carry = 0;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum = (int) $leftDigits[$i] + (int) $rightDigits[$i] + $carry;
            $digits[$i] = (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry === 1) {
            $digits = '1' . $digits;
        }

        $integer = ltrim(substr($digits, 0, strlen($digits) - $scale), '0');

        return ($integer === '' ? '0' : $integer) . ($scale === 0 ? '' : '.' . substr($digits, -$scale));
    }

    /**
     * @return -1|0|1
     */
    public static function compare(string $left, string $right): int
    {
        [$leftInteger, $leftFraction] = self::split(self::normalize($left));
        [$rightInteger, $rightFraction] = self::split(self::normalize($right));

        $byLength = strlen($leftInteger) <=> strlen($rightInteger);
        if ($byLength !== 0) {
            return $byLength;
        }
        $byInteger = strcmp($leftInteger, $rightInteger) <=> 0;
        if ($byInteger !== 0) {
            return $byInteger;
        }

        return strcmp($leftFraction, $rightFraction) <=> 0;
    }

    public static function normalize(string $decimal): string
    {
        [$integer, $fraction] = self::split($decimal);
        $integer = ltrim($integer, '0');
        $fraction = rtrim($fraction, '0');

        return ($integer === '' ? '0' : $integer) . ($fraction === '' ? '' : '.' . $fraction);
    }

    /**
     * @return array{string, string}
     */
    private static function split(string $decimal): array
    {
        $parts = explode('.', $decimal, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
