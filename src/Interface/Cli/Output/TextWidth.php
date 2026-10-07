<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Output;

/**
 * @internal
 */
final class TextWidth
{
    private const ZERO = '/[\p{Mn}\p{Me}\x{200B}-\x{200F}\x{FE0F}]/u';

    private const WIDE = '/[\x{1100}-\x{115F}\x{2E80}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}\x{FE30}-\x{FE4F}\x{FF00}-\x{FF60}'
        . '\x{FFE0}-\x{FFE6}\x{1F300}-\x{1F64F}\x{1F900}-\x{1F9FF}\x{20000}-\x{3FFFD}]/u';

    private function __construct()
    {
    }

    public static function width(string $text): int
    {
        if (preg_match_all('/./su', $text, $matches) === false || preg_match('//u', $text) !== 1) {
            return strlen($text);
        }

        $width = 0;
        foreach ($matches[0] as $char) {
            $width += self::charWidth($char);
        }

        return $width;
    }

    public static function truncate(string $text, int $max): string
    {
        if (self::width($text) <= $max) {
            return $text;
        }
        if (preg_match('//u', $text) !== 1) {
            return substr($text, 0, max(0, $max - 1)) . '…';
        }

        preg_match_all('/./su', $text, $matches);
        $result = '';
        $width = 0;
        foreach ($matches[0] as $char) {
            $charWidth = self::charWidth($char);
            if ($width + $charWidth > $max - 1) {
                break;
            }
            $result .= $char;
            $width += $charWidth;
        }

        return $result . '…';
    }

    public static function sanitize(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', ' ', $text);
    }

    private static function charWidth(string $char): int
    {
        if (preg_match(self::ZERO, $char) === 1) {
            return 0;
        }

        return preg_match(self::WIDE, $char) === 1 ? 2 : 1;
    }
}
