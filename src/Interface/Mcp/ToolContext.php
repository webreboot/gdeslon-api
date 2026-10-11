<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Domain\Shared\DataOrigin;
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
        #[\SensitiveParameter]
        public readonly Environment $environment,
        public readonly Console $console,
        public readonly bool $revealLinks = false,
    ) {
    }

    public function gdeslon(): GdeSlon
    {
        return $this->gdeslon ??= ($this->factory)();
    }

    public function categories(): CategoryTree
    {
        $tree = $this->gdeslon()->categories();
        $this->warnStale($tree->origin(), 'категории');

        return $tree;
    }

    public function merchants(): MerchantList
    {
        $list = $this->gdeslon()->merchants(public: $this->environment->token() === null);
        $this->warnStale($list->origin(), 'магазины');

        return $list;
    }

    public function log(string $message): void
    {
        try {
            $this->console->err('gdeslon mcp: ' . $message . "\n");
        } catch (\Webreboot\GdeSlon\Interface\Cli\OutputFailedException) {
        }
    }

    private function warnStale(DataOrigin $origin, string $what): void
    {
        if (!$origin->isCurrent()) {
            $this->log(sprintf('API недоступен, %s — из сохранённой копии и могут быть устаревшими', $what));
        }
    }
}
