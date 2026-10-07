<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

final class Fixtures
{
    public static function read(string $relative): string
    {
        $path = dirname(__DIR__) . '/Fixtures/' . $relative;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Нет фикстуры ' . $relative);
        }

        return $content;
    }

    public static function json(string $relative): mixed
    {
        return json_decode(self::read($relative), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}
