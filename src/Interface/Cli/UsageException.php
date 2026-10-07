<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Неверный вызов CLI: команда, опция, значение, файл окружения — код выхода 2.
 *
 * @internal
 */
final class UsageException extends \InvalidArgumentException implements GdeSlonException
{
}
