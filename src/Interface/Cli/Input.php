<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * @internal
 */
final class Input
{
    /**
     * @param list<string>                            $positionals
     * @param array<string, string|true|list<string>> $options
     */
    public function __construct(private readonly array $positionals, private readonly array $options)
    {
    }

    /**
     * @return list<string>
     */
    public function positionals(): array
    {
        return $this->positionals;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) === true;
    }

    public function value(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    public function list(string $name): array
    {
        $value = $this->options[$name] ?? [];

        return is_array($value) ? $value : [];
    }
}
