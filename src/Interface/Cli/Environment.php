<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Environment
{
    public const TOKEN = 'GDESLON_API_TOKEN';
    public const USER_ID = 'GDESLON_USER_ID';
    public const API_KEY = 'GDESLON_API_KEY';
    public const CACHE_DIR = 'GDESLON_CACHE_DIR';
    public const FEED_URLS = 'GDESLON_FEED_URLS';

    /**
     * @param array<string, string> $env
     * @param array<string, string> $file
     */
    public function __construct(#[\SensitiveParameter] private readonly array $env, #[\SensitiveParameter] private readonly array $file = [])
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
     * @return list<string>
     */
    public function feedUrls(): array
    {
        return preg_split('/\s+/', $this->get(self::FEED_URLS) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return list<string>
     */
    public function secrets(): array
    {
        $secrets = array_values(array_filter([$this->token(), $this->apiKey()], static fn (?string $secret): bool => $secret !== null));
        foreach ($this->feedUrls() as $url) {
            $secrets[] = $url;
            $name = basename((string) parse_url($url, PHP_URL_PATH));
            foreach ([$name, pathinfo($name, PATHINFO_FILENAME)] as $part) {
                if (strlen($part) >= 4) {
                    $secrets[] = $part;
                }
            }
        }

        return array_values(array_unique($secrets));
    }

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

    public function assertCredentials(Credentials $credentials): void
    {
        $token = $this->token();
        if ($token !== null && ($credentials === Credentials::Token || $credentials === Credentials::TokenOptional) && preg_match('/^[\x21-\x7E]+\z/', $token) !== 1) {
            throw new MissingCredentialsException('GDESLON_API_TOKEN задан неверно: допустимы только печатные символы ASCII без пробелов');
        }
        if ($credentials === Credentials::Token && $token === null) {
            throw new MissingCredentialsException(
                'Нужен токен XML API: задайте переменную окружения GDESLON_API_TOKEN (или --env-file). Токен — https://gdeslon.ru/api_settings/xml',
            );
        }
        if ($credentials !== Credentials::SalesKeys) {
            return;
        }
        $userId = $this->userId();
        $apiKey = $this->apiKey();
        if ($userId === null || $apiKey === null) {
            throw new MissingCredentialsException(
                'Нужны ключи API по продажам: задайте вместе GDESLON_USER_ID и GDESLON_API_KEY (или --env-file). Ключи — https://gdeslon.ru/api_settings/orders',
            );
        }
        try {
            new Config(userId: $userId, apiKey: $apiKey);
        } catch (InvalidArgumentException $e) {
            throw new MissingCredentialsException('Ключи не приняты: ' . $e->getMessage(), 0, $e);
        }
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
