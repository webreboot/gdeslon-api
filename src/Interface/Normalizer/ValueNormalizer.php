<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Normalizer;

use Webreboot\GdeSlon\Domain\Shared\Money;

/**
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
