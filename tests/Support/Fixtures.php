<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

final class Fixtures
{
    /**
     * Содержимое файла из tests/Fixtures (путь относительно этого каталога).
     */
    public static function read(string $relative): string
    {
        $path = dirname(__DIR__) . '/Fixtures/' . $relative;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Нет фикстуры ' . $relative);
        }

        return $content;
    }

    /**
     * Фикстура JSON, разобранная так же, как это делает ApiClient (ассоциативные массивы).
     */
    public static function json(string $relative): mixed
    {
        return json_decode(self::read($relative), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}
