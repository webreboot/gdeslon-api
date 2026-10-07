<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/**
 * Строка запроса или тело application/x-www-form-urlencoded → параметры. В отличие от parse_str() имена не искажаются
 * («sub.id», «my profit», «a[]» остаются как есть): имена задаёт вебмастер в кабинете.
 *
 * @internal
 */
final class QueryStringParser
{
    public const MAX_PARAMETERS = 200;

    /**
     * @return array<string, string>
     *
     * @throws InvalidPostbackException повтор имени, больше 200 параметров
     */
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
