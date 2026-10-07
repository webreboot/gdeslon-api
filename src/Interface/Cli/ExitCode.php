<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * @internal
 */
final class ExitCode
{
    public const OK = 0;

    public const FAILURE = 1;

    public const USAGE = 2;

    public const ACCESS = 3;

    public const CLAIM_UNCONFIRMED = 4;

    public const CLAIM_DUPLICATE = 5;

    private function __construct()
    {
    }
}
