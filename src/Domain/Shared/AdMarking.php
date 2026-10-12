<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

/**
 * @internal
 */
final class AdMarking
{
    private const VALUE = '(?=[A-Za-z]*[0-9])[A-Za-z0-9]{4,64}';

    // текст без CDATA не проходит html_entity_decode: неразрывный пробел доходит сущностью
    private const END = '(?:\z|\s|&(?:nbsp|#160|#[xX][aA]0);|<|[)\]}»"\'“”„’]++(?![\p{L}\p{N}_\-\/%+=&#]))';

    private const ERID = '/(?:(?<!\S)(?i:erid)\s*+=\s*+|(?<![\p{L}\p{N}_\-?&#;\/=])(?i:erid)(?:\s*+:\s*+|\s++))('
        . self::VALUE . ')(?=' . self::END . '|[.,;:!?](?=' . self::END . '))/u';

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
        return preg_match('/\A' . self::VALUE . '\z/', $value) === 1;
    }
}
