<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

/**
 * @internal
 */
final class ProtocolVersion
{
    public const MODERN = ['2026-07-28'];

    public const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private const STRUCTURED_SINCE = '2025-06-18';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return [...self::MODERN, ...self::LEGACY];
    }

    public static function isModern(string $version): bool
    {
        return in_array($version, self::MODERN, true);
    }

    public static function negotiateLegacy(string $requested): string
    {
        return in_array($requested, self::LEGACY, true) ? $requested : self::LEGACY[0];
    }

    public static function supportsStructuredContent(string $version): bool
    {
        return strcmp($version, self::STRUCTURED_SINCE) >= 0;
    }
}
