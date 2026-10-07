<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Interface\Cli\Output\JsonOutput;

/**
 * Всё, что нужно команде: фасад SDK (создаётся при первом обращении), консоль, часы, формат вывода, окружение (ключи).
 *
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
        public readonly Environment $environment = new Environment([]),
    ) {
    }

    public function gdeslon(): GdeSlon
    {
        return $this->gdeslon ??= ($this->factory)();
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
     * Предупреждение о записях ответа с битыми данными (в JSON они в поле skipped).
     *
     * @param list<string> $skipped
     */
    public function warnSkipped(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }
        $this->notice(sprintf('Пропущено записей с битыми данными: %d', count($skipped)));
        foreach (array_slice($skipped, 0, self::SKIPPED_SHOWN) as $reason) {
            $this->notice('  ' . $reason);
        }
    }

    /**
     * Московское «сегодня» и время для показа.
     */
    public static function moscow(?\DateTimeImmutable $moment, string $format = 'Y-m-d H:i'): ?string
    {
        return $moment?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format($format);
    }
}
