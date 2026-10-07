<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * @internal
 */
final class ArgvParser
{
    /**
     * @param list<string> $argv
     * @param list<Option> $options
     */
    public static function parse(array $argv, array $options): Input
    {
        $byName = [];
        $byShort = [];
        foreach ($options as $option) {
            $byName[$option->name] = $option;
            if ($option->short !== null) {
                $byShort[$option->short] = $option;
            }
        }

        $positionals = [];
        $values = [];
        $endOfOptions = false;
        for ($i = 0, $count = count($argv); $i < $count; $i++) {
            $token = $argv[$i];
            if (preg_match('//u', $token) !== 1) {
                throw new UsageException('Аргумент командной строки не в UTF-8');
            }
            if ($endOfOptions || $token === '-' || !str_starts_with($token, '-')) {
                $positionals[] = $token;

                continue;
            }
            if ($token === '--') {
                $endOfOptions = true;

                continue;
            }

            if (!str_starts_with($token, '--')) {
                $option = $byShort[substr($token, 1)] ?? throw new UsageException(sprintf(
                    'Неизвестная опция «%s» (если это значение, например минус-слово запроса, поставьте перед ним «--»)',
                    $token,
                ));
                $values[$option->name] = true;

                continue;
            }

            [$name, $value] = str_contains($token, '=') ? explode('=', substr($token, 2), 2) : [substr($token, 2), null];
            $option = $byName[$name] ?? throw new UsageException(sprintf('Неизвестная опция «--%s»', $name));
            $name = $option->name;

            if ($option->kind === Option::FLAG) {
                if ($value !== null) {
                    throw new UsageException(sprintf('Опция «--%s» не принимает значения', $name));
                }
                $values[$name] = true;

                continue;
            }

            if ($value === null) {
                if ($i + 1 >= $count) {
                    throw new UsageException(sprintf('У опции «--%s» нет значения', $name));
                }
                $value = $argv[++$i];
                if (str_starts_with($value, '-') && $value !== '-') {
                    throw new UsageException(sprintf('У опции «--%1$s» нет значения (значение с «-» в начале — как «--%1$s=…»)', $name));
                }
                if (preg_match('//u', $value) !== 1) {
                    throw new UsageException(sprintf('Значение опции «--%s» не в UTF-8', $name));
                }
            }
            if (trim($value) === '') {
                throw new UsageException(sprintf('Пустое значение опции «--%s»', $name));
            }

            if ($option->kind === Option::LIST) {
                $items = explode(',', $value);
                foreach ($items as $item) {
                    if (trim($item) === '') {
                        throw new UsageException(sprintf('Пустой элемент в списке «--%s»', $name));
                    }
                }
                $previous = $values[$name] ?? [];
                $values[$name] = [...(is_array($previous) ? $previous : []), ...array_map('trim', $items)];

                continue;
            }

            if (array_key_exists($name, $values)) {
                throw new UsageException(sprintf('Опция «--%s» указана дважды', $name));
            }
            $values[$name] = $value;
        }

        return new Input($positionals, $values);
    }
}
