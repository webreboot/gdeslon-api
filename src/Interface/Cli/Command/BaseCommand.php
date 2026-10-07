<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\OptionValues;
use Webreboot\GdeSlon\Interface\Cli\UsageException;

/**
 * @internal
 */
abstract class BaseCommand implements Command
{
    public function arguments(): string
    {
        return '';
    }

    public function help(): string
    {
        return '';
    }

    public function options(): array
    {
        return [];
    }

    public function defaultTimeout(): ?float
    {
        return null;
    }

    /**
     * @param list<string> $arguments
     */
    protected static function noArguments(array $arguments): void
    {
        if ($arguments !== []) {
            throw new UsageException(sprintf('Лишний аргумент «%s»', $arguments[0]));
        }
    }

    /**
     * @param list<string> $arguments
     */
    protected static function idArgument(array $arguments, string $what): int
    {
        if (count($arguments) !== 1) {
            throw new UsageException(sprintf('Нужен один аргумент — %s', $what));
        }
        $tooBig = strlen($arguments[0]) === 19 && strcmp($arguments[0], (string) PHP_INT_MAX) > 0;
        if (preg_match('/^[1-9]\d{0,18}\z/', $arguments[0]) !== 1 || $tooBig) {
            throw new UsageException(sprintf('%s: ожидалось положительное целое число, получено «%s»', $what, $arguments[0]));
        }

        return (int) $arguments[0];
    }

    protected static function intOption(Input $input, string $name): ?int
    {
        $value = $input->value($name);

        return $value === null ? null : OptionValues::positiveInt($value, $name);
    }

    /**
     * @return list<int>
     */
    protected static function intList(Input $input, string $name): array
    {
        return OptionValues::positiveInts($input->list($name), $name);
    }
}
