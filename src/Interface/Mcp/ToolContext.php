<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Interface\Cli\Console;
use Webreboot\GdeSlon\Interface\Cli\Environment;

/**
 * Всё, что нужно инструментам: фасад SDK (создаётся при первом вызове и живёт, пока работает сервер), часы, ключи,
 * консоль (маска секретов и лог в stderr), флаг --reveal-links.
 *
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

    /**
     * Строка в лог (stderr): маска секретов и замена управляющих символов — в Console; сбой записи не роняет сервер.
     */
    public function log(string $message): void
    {
        try {
            $this->console->err('gdeslon mcp: ' . $message . "\n");
        } catch (\Webreboot\GdeSlon\Interface\Cli\OutputFailedException) {
            // stderr недоступен — протоколу это не мешает
        }
    }
}
