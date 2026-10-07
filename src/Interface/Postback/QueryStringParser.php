<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/**
 * @internal
 */
final class QueryStringParser
{
    public const MAX_PARAMETERS = 200;

    /** @return array<string, string> */
    public static function parse(string $query): array
    {
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            $value = $parts[1] ?? '';
            if (array_key_exists($name, $parameters)) {
                throw InvalidPostbackException::forField($name, sprintf('Postback: параметр «%s» передан несколько раз', PostbackText::safe($name)));
            }
            if (count($parameters) >= self::MAX_PARAMETERS) {
                throw new InvalidPostbackException(sprintf('Postback: больше %d параметров', self::MAX_PARAMETERS));
            }
            $parameters[$name] = urldecode($value);
        }

        return $parameters;
    }
}
