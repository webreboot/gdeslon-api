<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Команде не хватает ключей в окружении: запрос не отправляется, код выхода ExitCode::ACCESS.
 *
 * @internal
 */
final class MissingCredentialsException extends \RuntimeException implements GdeSlonException
{
}
