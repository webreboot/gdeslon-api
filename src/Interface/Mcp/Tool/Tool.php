<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;

/**
 * @internal
 */
interface Tool
{
    public function name(): string;

    public function title(): string;

    public function description(): string;

    /**
     * @return array<string, \stdClass>
     */
    public function inputProperties(): array;

    /**
     * @return list<string>
     */
    public function required(): array;

    /**
     * @return array<string, mixed>
     */
    public function outputShape(): array;

    public function credentials(): Credentials;

    public function exposesToken(): bool;

    /**
     * @return array<string, mixed>
     */
    public function call(Arguments $arguments, ToolContext $context): array;
}
