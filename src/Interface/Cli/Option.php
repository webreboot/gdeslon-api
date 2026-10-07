<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * Объявление опции команды: флаг, значение или список (через запятую и/или повтором).
 *
 * @internal
 */
final class Option
{
    public const FLAG = 'flag';
    public const VALUE = 'value';
    public const LIST = 'list';

    private function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $description,
        public readonly ?string $short = null,
        public readonly string $valueName = 'VALUE',
    ) {
    }

    public static function flag(string $name, string $description, ?string $short = null): self
    {
        return new self($name, self::FLAG, $description, $short);
    }

    public static function value(string $name, string $description, string $valueName = 'VALUE'): self
    {
        return new self($name, self::VALUE, $description, null, $valueName);
    }

    public static function listOf(string $name, string $description, string $valueName = 'ID,…'): self
    {
        return new self($name, self::LIST, $description, null, $valueName);
    }

    /**
     * «--limit=N» для справки.
     */
    public function usage(): string
    {
        $usage = ($this->short === null ? '' : '-' . $this->short . ', ') . '--' . $this->name;

        return $this->kind === self::FLAG ? $usage : $usage . '=' . $this->valueName;
    }
}
