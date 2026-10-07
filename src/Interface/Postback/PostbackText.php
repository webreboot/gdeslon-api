<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/** @internal */
final class PostbackText
{
    private const MAX_LENGTH = 40;

    public static function safe(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = (string) preg_replace('/[\x80-\xFF]/', '?', $value);
        }
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return preg_match('/^.{0,' . self::MAX_LENGTH . '}/us', $value, $match) === 1 && $match[0] !== $value
            ? $match[0] . '…'
            : $value;
    }
}
