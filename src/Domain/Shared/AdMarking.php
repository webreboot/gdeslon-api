<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

/**
 * @internal
 */
final class AdMarking
{
    private const ERID = '/(?<![\p{L}\p{N}_\-?&#;\/=])(?i:erid)(?:\s*[:=]\s*|\s+)(?=[A-Za-z]*[0-9])([A-Za-z0-9]{4,64})(?=\z|\s|[.,;:!?](?=\z|\s|[)\]}»"\'“”„’<])|[)\]}»"\'“”„’<&#])/u';

    private function __construct()
    {
    }

    public static function erid(?string $marking): ?string
    {
        if ($marking === null || preg_match(self::ERID, $marking, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function isErid(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]{4,64}\z/', $value) === 1;
    }
}
