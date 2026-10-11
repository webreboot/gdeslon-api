<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class Currency
{
    private const LEGACY = ['RUR' => 'RUB'];

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function normalize(string $code): string
    {
        $trimmed = trim($code);
        if (preg_match('/^[A-Za-z]{3}\z/', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf('Код валюты «%s» — не три латинские буквы', $trimmed));
        }
        $upper = strtr($trimmed, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');

        return self::LEGACY[$upper] ?? $upper;
    }
}
