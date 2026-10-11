<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

/**
 * @internal
 */
final class AdMarking
{
    private const ERID = '/(?<![\p{L}\p{N}])erid(?:\s*:\s*|\s+)([A-Za-z0-9]{4,64})(?![\p{L}\p{N}])/iu';

    private function __construct()
    {
    }

    public static function erid(?string $marking): ?string
    {
        if ($marking === null || (int) preg_match_all(self::ERID, $marking, $matches) === 0) {
            return null;
        }

        return $matches[1][count($matches[1]) - 1];
    }

    public static function isErid(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]{4,64}\z/', $value) === 1;
    }
}
