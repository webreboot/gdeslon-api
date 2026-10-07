<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * Переменные окружения CLI: ключи API (только отсюда, не из аргументов) и каталог кэша. Окружение процесса важнее
 * файла `--env-file`; пустое значение — «не задано».
 *
 * @internal
 */
final class Environment
{
    public const TOKEN = 'GDESLON_API_TOKEN';
    public const USER_ID = 'GDESLON_USER_ID';
    public const API_KEY = 'GDESLON_API_KEY';
    public const CACHE_DIR = 'GDESLON_CACHE_DIR';

    /**
     * @param array<string, string> $env  окружение процесса
     * @param array<string, string> $file значения из --env-file
     */
    public function __construct(private readonly array $env, private readonly array $file = [])
    {
    }

    public function token(): ?string
    {
        return $this->get(self::TOKEN);
    }

    public function userId(): ?string
    {
        return $this->get(self::USER_ID);
    }

    public function apiKey(): ?string
    {
        return $this->get(self::API_KEY);
    }

    /**
     * Значения, которые нельзя печатать (токен и ключ API по продажам).
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->token(), $this->apiKey()], static fn (?string $secret): bool => $secret !== null));
    }

    /**
     * GDESLON_CACHE_DIR, иначе XDG_CACHE_HOME/gdeslon-api, ~/.cache/gdeslon-api, %LOCALAPPDATA%\gdeslon-api\cache; null — нет.
     */
    public function cacheDirectory(): ?string
    {
        $dir = $this->get(self::CACHE_DIR);
        if ($dir !== null) {
            return $dir;
        }
        $xdg = $this->system('XDG_CACHE_HOME');
        if ($xdg !== null) {
            return rtrim($xdg, '/\\') . '/gdeslon-api';
        }
        $home = $this->system('HOME');
        if ($home !== null) {
            return rtrim($home, '/\\') . '/.cache/gdeslon-api';
        }
        $local = $this->system('LOCALAPPDATA');

        return $local === null ? null : rtrim($local, '/\\') . '\\gdeslon-api\\cache';
    }

    private function get(string $name): ?string
    {
        return $this->system($name) ?? self::nonEmpty($this->file[$name] ?? null);
    }

    private function system(string $name): ?string
    {
        return self::nonEmpty($this->env[$name] ?? null);
    }

    private static function nonEmpty(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
