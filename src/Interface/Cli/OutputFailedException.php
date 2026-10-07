<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * @internal
 */
final class OutputFailedException extends \RuntimeException implements GdeSlonException
{
}
