<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Output;

/**
 * Таблица для терминала: заголовок, разделитель, колонки через два пробела; ширина — по TextWidth, ячейки очищаются от
 * управляющих символов и обрезаются по максимуму колонки; null — «—».
 *
 * @internal
 */
final class Table
{
    private const EMPTY = '—';

    private function __construct()
    {
    }

    /**
     * @param list<string>             $headers
     * @param list<list<string|null>>  $rows
     * @param array<int, int>          $maxWidths номер колонки → максимальная ширина
     */
    public static function render(array $headers, array $rows, array $maxWidths = []): string
    {
        $cells = [];
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($headers) as $column) {
                $value = $row[$column] ?? null;
                $value = $value === null || trim($value) === '' ? self::EMPTY : TextWidth::sanitize($value);
                $line[] = isset($maxWidths[$column]) ? TextWidth::truncate($value, $maxWidths[$column]) : $value;
            }
            $cells[] = $line;
        }

        $widths = [];
        foreach ($headers as $column => $header) {
            $widths[$column] = TextWidth::width($header);
            foreach ($cells as $line) {
                $widths[$column] = max($widths[$column], TextWidth::width($line[$column]));
            }
        }

        $output = self::line($headers, $widths) . self::line(array_map(static fn (int $width): string => str_repeat('-', $width), $widths), $widths);
        foreach ($cells as $line) {
            $output .= self::line($line, $widths);
        }

        return $output;
    }

    /**
     * @param array<int, string> $cells
     * @param array<int, int>    $widths
     */
    private static function line(array $cells, array $widths): string
    {
        $parts = [];
        foreach ($cells as $column => $cell) {
            $parts[] = $cell . str_repeat(' ', max(0, $widths[$column] - TextWidth::width($cell)));
        }

        return rtrim(implode('  ', $parts)) . "\n";
    }
}
