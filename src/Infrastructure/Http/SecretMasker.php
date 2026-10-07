<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

/** @internal */
final class SecretMasker
{
    private const MASK = '***';

    private const SECRET_PARAMETERS = '_gs_at|api_token|token|api_key|key|password';

    public static function maskUrl(string $url): string
    {
        $url = preg_replace('~^([a-z][a-z0-9+.-]*://)[^/?#@\s]+@~i', '$1' . self::MASK . '@', $url) ?? $url;

        return self::maskText($url);
    }

    // короткие значения не маскируем: замена испортила бы текст, а угадать их всё равно легко
    private const MIN_SECRET_LENGTH = 4;

    /**
     * @return list<string>
     */
    public static function secretValues(HttpRequest $request): array
    {
        $values = [];
        foreach ($request->query() as $name => $value) {
            if (preg_match('~^(?:' . self::SECRET_PARAMETERS . ')$~i', $name) !== 1) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item)) {
                    $values[] = $item;
                }
            }
        }

        $authorization = trim($request->header('Authorization') ?? '');
        if (preg_match('~^Basic\s+(\S+)$~i', $authorization, $match) === 1) {
            $values[] = $match[1];
            $credentials = base64_decode($match[1], true);
            if (is_string($credentials) && str_contains($credentials, ':')) {
                $values[] = substr($credentials, strpos($credentials, ':') + 1);
            }
        } elseif (preg_match('~^Bearer\s+(\S+)$~i', $authorization, $match) === 1) {
            $values[] = $match[1];
        }

        return array_values(array_filter($values, static fn (string $value): bool => strlen($value) >= self::MIN_SECRET_LENGTH));
    }

    /**
     * @param list<string> $values
     */
    public static function maskValues(string $text, array $values): string
    {
        return $values === [] ? $text : str_replace($values, self::MASK, $text);
    }

    public static function maskText(string $text): string
    {
        return preg_replace(
            '~([?&;](?:' . self::SECRET_PARAMETERS . ')=)[^&#\s"\'<>]*~i',
            '$1' . self::MASK,
            $text,
        ) ?? $text;
    }
}
