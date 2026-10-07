<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Output;

/**
 * @internal
 */
final class JsonOutput
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return (string) preg_replace_callback('/\xC2([\x80-\x9F])/', static fn (array $m): string => sprintf('\\u%04x', ord($m[1])), $json) . "\n";
    }
}
