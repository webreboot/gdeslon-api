<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * Какие ключи нужны команде.
 *
 * @internal
 */
enum Credentials
{
    case None;
    /** Токен XML API, если задан, меняет результат (магазины вебмастера вместо публичного каталога). */
    case TokenOptional;
    case Token;
    /** ID пользователя и ключ API по продажам. */
    case SalesKeys;
}
