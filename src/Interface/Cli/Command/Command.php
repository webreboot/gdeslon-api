<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;

/**
 * Команда CLI. Имя, опции и коды выхода — публичный контракт (docs/cli.md); классы — внутренние.
 *
 * @internal
 */
interface Command
{
    /**
     * Полное имя: «search», «lost-orders submit».
     */
    public function name(): string;

    /**
     * Позиционные аргументы для справки: «<ID>», «[<запрос>…]».
     */
    public function arguments(): string;

    public function summary(): string;

    /**
     * Подробности для `gdeslon help <команда>`.
     */
    public function help(): string;

    /**
     * @return list<Option>
     */
    public function options(): array;

    public function credentials(): Credentials;

    /**
     * Таймаут запроса по умолчанию, если не задан `--timeout`; null — как в Config.
     */
    public function defaultTimeout(): ?float;

    /**
     * @param list<string> $arguments позиционные аргументы после имени команды
     *
     * @return int код выхода
     */
    public function execute(Input $input, array $arguments, CommandContext $context): int;
}
