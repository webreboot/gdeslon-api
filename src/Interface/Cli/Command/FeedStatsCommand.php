<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\FeedStatistics;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Output\Details;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Cli\UsageException;

/**
 * @internal
 */
final class FeedStatsCommand extends BaseCommand
{
    public function name(): string
    {
        return 'feed stats';
    }

    public function arguments(): string
    {
        return '<FILE>';
    }

    public function summary(): string
    {
        return 'статистика товарной выгрузки: офферы, магазины, param (ключ не нужен)';
    }

    public function help(): string
    {
        return "FILE — выгрузка из кабинета: YML (.xml) или zip-архив, как его отдаёт ссылка выгрузки\n"
            . '(zip распаковывается во временный файл и удаляется).';
    }

    public function credentials(): Credentials
    {
        return Credentials::None;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        if (count($arguments) !== 1) {
            throw new UsageException('Нужен один аргумент — путь к файлу выгрузки');
        }
        $path = $arguments[0];
        $unpacked = null;

        try {
            if (self::isZip($path)) {
                $unpacked = tempnam(sys_get_temp_dir(), 'gdeslon-feed-stats');
                if ($unpacked === false) {
                    $context->notice('Не удалось создать временный файл для распаковки');

                    return ExitCode::FAILURE;
                }
                $context->gdeslon()->unpackFeed($path, $unpacked);
            }
            $feed = $context->gdeslon()->openFeed($unpacked ?? $path);
            $stats = FeedStatistics::of($feed);
        } finally {
            if ($unpacked !== null && $unpacked !== false) {
                @unlink($unpacked);
            }
        }

        if ($context->json) {
            $context->json($stats->toArray());
        } else {
            $context->text(self::render($stats));
        }
        $context->warnSkipped($feed->skipped(), $feed->skippedCount());

        return ExitCode::OK;
    }

    private static function render(FeedStatistics $stats): string
    {
        $coverage = static fn (int $count): string => sprintf('%d (%s)', $count, $stats->percent($count));
        $text = Details::render([
            ['Дата выгрузки', CommandContext::moscow($stats->generatedAt)],
            ['Офферов', (string) $stats->offers],
            ['Пропущено', (string) $stats->skipped],
            ['Магазинов', (string) count($stats->merchants)],
            ['Категорий', (string) $stats->categories],
            ['С цветом', $coverage($stats->coverage['color'])],
            ['С размером', $coverage($stats->coverage['size'])],
            ['С полом', $coverage($stats->coverage['gender'])],
            ['С erid', $coverage($stats->coverage['erid'])],
        ]);
        $top = $stats->topMerchants();
        $text .= "\n" . Table::render(['Магазин', 'Офферов'], array_map(
            static fn (int $id, int $count): array => [(string) $id, (string) $count],
            array_keys($top),
            array_values($top),
        ));
        if ($stats->params !== []) {
            $text .= "\n" . Table::render(['Параметр', 'Офферов', 'Доля'], array_map(
                static fn (string $name, int $count): array => [$name, (string) $count, $stats->percent($count)],
                array_map('strval', array_keys($stats->params)),
                array_values($stats->params),
            ));
        }

        return $text;
    }

    private static function isZip(string $path): bool
    {
        if (str_contains($path, '://') || !is_file($path)) {
            return false;
        }

        return file_get_contents($path, false, null, 0, 4) === "PK\x03\x04";
    }
}
