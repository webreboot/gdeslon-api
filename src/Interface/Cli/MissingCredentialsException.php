<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * @internal
 */
final class MissingCredentialsException extends \RuntimeException implements GdeSlonException
{
}
