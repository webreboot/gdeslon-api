<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;

/**
 * @internal
 */
interface Command
{
    public function name(): string;

    public function arguments(): string;

    public function summary(): string;

    public function help(): string;

    /**
     * @return list<Option>
     */
    public function options(): array;

    public function credentials(): Credentials;

    public function defaultTimeout(): ?float;

    /**
     * @param list<string> $arguments
     */
    public function execute(Input $input, array $arguments, CommandContext $context): int;
}
