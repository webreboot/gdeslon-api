<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

/**
 * @internal
 */
final class HttpUrl
{
    private function __construct()
    {
    }

    public static function isValid(string $url): bool
    {
        return preg_match('~^https?://[^\s/?#]+~i', $url) === 1;
    }

    public static function orNull(?string $url): ?string
    {
        return $url !== null && self::isValid($url) ? $url : null;
    }
}
