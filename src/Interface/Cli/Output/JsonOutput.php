<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Output;

/**
 * JSON для скриптов: UTF-8 без экранирования кириллицы и «/», с отступами, перевод строки в конце.
 *
 * @internal
 */
final class JsonOutput
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \JsonException
     */
    public static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        // C1 (U+0080–U+009F) json_encode оставляет как есть, а Console заменил бы их пробелом и исказил данные;
        // экранированные они не управляют терминалом. Вне строк JSON — только ASCII, так что замена безопасна.
        return (string) preg_replace_callback('/\xC2([\x80-\x9F])/', static fn (array $m): string => sprintf('\\u%04x', ord($m[1])), $json) . "\n";
    }
}
