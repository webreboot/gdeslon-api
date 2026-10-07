<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * @internal
 */
enum Credentials
{
    case None;
    case TokenOptional;
    case Token;
    case SalesKeys;
}
