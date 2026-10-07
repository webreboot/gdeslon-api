<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Arguments
{
    public const MAX_LIST = 100;

    /** @var array<string, mixed> */
    private readonly array $values;

    /** @var array<string, true> */
    private array $known = [];

    /** @var list<string> */
    private array $errors = [];

    public function __construct(\stdClass $arguments)
    {
        $values = [];
        foreach (get_object_vars($arguments) as $name => $value) {
            $values[(string) $name] = $value;
        }
        $this->values = $values;
    }

    public function int(string $name, int $min, int $max = PHP_INT_MAX): ?int
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        $int = self::integer($value);
        if ($int === null || $int < $min || $int > $max) {
            $this->errors[] = sprintf('%s: ожидалось целое число %s, получено %s', $name, self::range($min, $max), self::shown($value));

            return null;
        }

        return $int;
    }

    /**
     * @return list<int>
     */
    public function intList(string $name, int $min = 1): array
    {
        $items = $this->listOf($name);
        $ints = [];
        foreach ($items as $i => $item) {
            $int = self::integer($item);
            if ($int === null || $int < $min) {
                $this->errors[] = sprintf('%s[%d]: ожидалось целое число %s, получено %s', $name, $i, self::range($min, PHP_INT_MAX), self::shown($item));

                return [];
            }
            $ints[] = $int;
        }

        return $ints;
    }

    public function string(string $name, int $maxLength): ?string
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }

        return $this->text($name, $value, $maxLength);
    }

    /**
     * @return list<string>
     */
    public function stringList(string $name, int $maxLength): array
    {
        $strings = [];
        foreach ($this->listOf($name) as $i => $item) {
            $string = $this->text(sprintf('%s[%d]', $name, $i), $item, $maxLength);
            if ($string === null) {
                return [];
            }
            $strings[] = $string;
        }

        return $strings;
    }

    public function bool(string $name): ?bool
    {
        $value = $this->value($name);
        if ($value === null || is_bool($value)) {
            return $value;
        }
        $this->errors[] = sprintf('%s: ожидалось true или false, получено %s', $name, self::shown($value));

        return null;
    }

    /**
     * @template T
     *
     * @param array<string, T> $choices
     *
     * @return T|null
     */
    public function choice(string $name, array $choices): mixed
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        if (is_string($value) && array_key_exists($value, $choices)) {
            return $choices[$value];
        }
        $this->errors[] = sprintf('%s: допустимые значения — %s; получено %s', $name, implode(', ', array_keys($choices)), self::shown($value));

        return null;
    }

    /**
     * @template T
     *
     * @param array<string, T> $choices
     *
     * @return list<T>
     */
    public function choiceList(string $name, array $choices): array
    {
        $values = [];
        foreach ($this->listOf($name) as $i => $item) {
            if (!is_string($item) || !array_key_exists($item, $choices)) {
                $this->errors[] = sprintf('%s[%d]: допустимые значения — %s; получено %s', $name, $i, implode(', ', array_keys($choices)), self::shown($item));

                return [];
            }
            $values[] = $choices[$item];
        }

        return $values;
    }

    public function date(string $name): ?string
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }
        $this->errors[] = sprintf('%s: ожидалась дата ГГГГ-ММ-ДД, получено %s', $name, self::shown($value));

        return null;
    }

    public function done(): void
    {
        foreach (array_keys($this->values) as $name) {
            if (!isset($this->known[$name])) {
                $this->errors[] = sprintf('неизвестный аргумент %s', $name);
            }
        }
        if ($this->errors !== []) {
            throw new InvalidArgumentException('Неверные аргументы: ' . implode('; ', $this->errors));
        }
    }

    public function reject(string $message): void
    {
        $this->errors[] = $message;
    }

    private function value(string $name): mixed
    {
        $this->known[$name] = true;

        return $this->values[$name] ?? null;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(string $name): array
    {
        $value = $this->value($name);
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            $this->errors[] = sprintf('%s: ожидался список, получено %s', $name, self::shown($value));

            return [];
        }
        if (count($value) > self::MAX_LIST) {
            $this->errors[] = sprintf('%s: не больше %d элементов, получено %d', $name, self::MAX_LIST, count($value));

            return [];
        }

        return array_values($value);
    }

    private function text(string $name, mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            $this->errors[] = sprintf('%s: ожидалась строка, получено %s', $name, self::shown($value));

            return null;
        }
        $value = trim($value);
        $length = preg_match_all('/./su', $value);
        if ($value === '' || $length > $maxLength) {
            $this->errors[] = sprintf('%s: ожидалась непустая строка до %d символов', $name, $maxLength);

            return null;
        }

        return $value;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value && $value >= -9.2233720368547E+18 && $value < 9.2233720368547E+18) {
            return (int) $value;
        }

        return null;
    }

    private static function range(int $min, int $max): string
    {
        return $max === PHP_INT_MAX ? sprintf('≥ %d', $min) : sprintf('от %d до %d', $min, $max);
    }

    private static function shown(mixed $value): string
    {
        try {
            $json = Json::encode($value);
        } catch (\JsonException) {
            $json = get_debug_type($value);
        }

        return strlen($json) > 60 ? substr($json, 0, 57) . '…' : $json;
    }
}
