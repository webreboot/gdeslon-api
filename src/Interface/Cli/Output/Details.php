<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Output;

/**
 * Карточка «Поле: значение» для одной записи; значения очищаются от управляющих символов, null — «—».
 *
 * @internal
 */
final class Details
{
    private function __construct()
    {
    }

    /**
     * @param list<array{string, string|null}> $fields
     */
    public static function render(array $fields): string
    {
        $width = 0;
        foreach ($fields as [$label]) {
            $width = max($width, TextWidth::width($label) + 1);
        }

        $output = '';
        foreach ($fields as [$label, $value]) {
            $value = $value === null || trim($value) === '' ? '—' : TextWidth::sanitize($value);
            $output .= $label . ':' . str_repeat(' ', $width - TextWidth::width($label) - 1) . ' ' . $value . "\n";
        }

        return $output;
    }
}
