<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * @internal
 */
final class OptionValues
{
    private function __construct()
    {
    }

    public static function positiveInt(string $value, string $option): int
    {
        $tooBig = strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0;
        if (preg_match('/^[1-9]\d{0,18}\z/', $value) !== 1 || $tooBig) {
            throw new UsageException(sprintf('«--%s»: ожидалось положительное целое число, получено «%s»', $option, $value));
        }

        return (int) $value;
    }

    /**
     * @param list<string> $values
     *
     * @return list<int>
     */
    public static function positiveInts(array $values, string $option): array
    {
        return array_map(static fn (string $value): int => self::positiveInt($value, $option), $values);
    }

    /**
     * @template T
     *
     * @param array<string, T> $choices
     *
     * @return T
     */
    public static function choice(string $value, array $choices, string $option): mixed
    {
        if (!array_key_exists($value, $choices)) {
            throw new UsageException(sprintf('«--%s»: допустимые значения — %s; получено «%s»', $option, implode(', ', array_keys($choices)), $value));
        }

        return $choices[$value];
    }
}
