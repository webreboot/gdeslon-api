<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Вывод не записан целиком (полный диск, закрытый поток): скрипт должен получить ошибку, а не код 0 с обрезанным JSON.
 *
 * @internal
 */
final class OutputFailedException extends \RuntimeException implements GdeSlonException
{
}
