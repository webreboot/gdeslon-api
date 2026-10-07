<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Записи с таким ID нет (get_*): результат инструмента с тегом [not_found].
 *
 * @internal
 */
final class NotFoundException extends \RuntimeException implements GdeSlonException
{
}
