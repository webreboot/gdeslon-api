<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * Файл окружения (`--env-file`): строки `KEY=VALUE`, `export KEY=VALUE`, комментарии `#`, значения в кавычках без
 * подстановок, CRLF и BOM. Читаются только ключи `GDESLON_*`; значения в сообщения об ошибках не попадают.
 *
 * @internal
 */
final class EnvFile
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     *
     * @throws UsageException
     */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new UsageException(sprintf('Файл окружения «%s» не найден или не читается', $path));
        }

        return self::parse((string) file_get_contents($path));
    }

    /**
     * @return array<string, string>
     *
     * @throws UsageException
     */
    public static function parse(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $values = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)\z/', $line, $match) !== 1) {
                throw new UsageException(sprintf('Файл окружения, строка %d: ожидалось KEY=VALUE', $index + 1));
            }
            if (!str_starts_with($match[1], 'GDESLON_')) {
                continue;
            }
            $value = trim($match[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$match[1]] = $value;
        }

        return $values;
    }
}
