<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Normalizer;

use Webreboot\GdeSlon\Domain\Shared\Money;

/**
 * Значения для JSON (схема — docs/cli.md, общая для CLI и MCP): деньги — строкой с валютой, моменты — ISO 8601 со
 * смещением, дни — «Y-m-d».
 *
 * @internal
 */
final class ValueNormalizer
{
    private function __construct()
    {
    }

    /**
     * @return array{amount: string, currency: string}|null
     */
    public static function money(?Money $money): ?array
    {
        return $money === null ? null : ['amount' => $money->amount(), 'currency' => $money->currency()];
    }

    public static function moment(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d\TH:i:sP');
    }

    public static function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d');
    }
}
