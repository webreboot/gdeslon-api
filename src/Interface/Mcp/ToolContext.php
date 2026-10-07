<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Interface\Cli\Console;
use Webreboot\GdeSlon\Interface\Cli\Environment;

/**
 * @internal
 */
final class ToolContext
{
    private ?GdeSlon $gdeslon = null;

    /**
     * @param \Closure(): GdeSlon $factory
     */
    public function __construct(
        private readonly \Closure $factory,
        public readonly Clock $clock,
        public readonly Environment $environment,
        public readonly Console $console,
        public readonly bool $revealLinks = false,
    ) {
    }

    public function gdeslon(): GdeSlon
    {
        return $this->gdeslon ??= ($this->factory)();
    }

    public function log(string $message): void
    {
        try {
            $this->console->err('gdeslon mcp: ' . $message . "\n");
        } catch (\Webreboot\GdeSlon\Interface\Cli\OutputFailedException) {
        }
    }
}
