<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Domain\Shared\DataOrigin;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Interface\Cli\Output\JsonOutput;

/**
 * @internal
 */
final class CommandContext
{
    private const SKIPPED_SHOWN = 3;

    private ?GdeSlon $gdeslon = null;

    /**
     * @param \Closure(): GdeSlon $factory
     */
    public function __construct(
        private readonly \Closure $factory,
        public readonly Console $console,
        public readonly Clock $clock,
        public readonly bool $json,
        #[\SensitiveParameter]
        public readonly Environment $environment = new Environment([]),
    ) {
    }

    public function gdeslon(): GdeSlon
    {
        return $this->gdeslon ??= ($this->factory)();
    }

    public function categories(bool $refresh = false): CategoryTree
    {
        $tree = $this->gdeslon()->categories(refresh: $refresh);
        $this->warnStale($tree->origin(), 'категории');

        return $tree;
    }

    public function merchants(bool $refresh = false): MerchantList
    {
        $list = $this->gdeslon()->merchants(public: $this->environment->token() === null, refresh: $refresh);
        $this->warnStale($list->origin(), 'магазины');

        return $list;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function json(array $data): void
    {
        $this->console->out(JsonOutput::encode($data));
    }

    public function text(string $text): void
    {
        $this->console->out($text);
    }

    public function notice(string $text): void
    {
        $this->console->err($text . "\n");
    }

    public function nothingFound(): void
    {
        $this->notice('Ничего не найдено.');
    }

    /**
     * @param list<string> $skipped
     */
    public function warnSkipped(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }
        $this->notice(sprintf('Пропущено записей: %d', count($skipped)));
        foreach (array_slice($skipped, 0, self::SKIPPED_SHOWN) as $reason) {
            $this->notice('  ' . $reason);
        }
    }

    public static function moscow(?\DateTimeImmutable $moment, string $format = 'Y-m-d H:i'): ?string
    {
        return $moment?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format($format);
    }

    private function warnStale(DataOrigin $origin, string $what): void
    {
        if (!$origin->isCurrent()) {
            $this->notice(sprintf('Внимание: API недоступен, %s — из сохранённой копии и могут быть устаревшими.', $what));
        }
    }
}
