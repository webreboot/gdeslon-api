<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Cli\UsageException;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;

/**
 * @internal
 */
final class CategoriesCommand extends BaseCommand
{
    public function name(): string
    {
        return 'categories';
    }

    public function arguments(): string
    {
        return '[<ID>]';
    }

    public function summary(): string
    {
        return 'дерево товарных категорий (ключ не нужен)';
    }

    public function help(): string
    {
        return "С ID — категория, её путь от корня и подкатегории. ID категорий подходят для\n"
            . 'gdeslon search --category / --exclude-category.';
    }

    public function options(): array
    {
        return [Option::value('depth', 'сколько уровней подкатегорий показать (по умолчанию — все)', 'N')];
    }

    public function credentials(): Credentials
    {
        return Credentials::None;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        if (count($arguments) > 1) {
            throw new UsageException(sprintf('Лишний аргумент «%s»', $arguments[1]));
        }
        $id = $arguments === [] ? null : self::idArgument($arguments, 'ID категории');
        $depth = self::intOption($input, 'depth');

        $tree = $context->gdeslon()->categories();
        if ($id !== null && !$tree->has($id)) {
            $context->notice(sprintf('Категории %d нет', $id));

            return ExitCode::FAILURE;
        }

        $start = $id === null ? [...$tree->roots(), ...$tree->orphans()] : [$tree->get($id)];
        $rows = [];
        foreach ($start as $category) {
            self::walk($tree, $category, 0, $depth, $rows);
        }

        if ($context->json) {
            $data = ['categories' => array_map(static fn (array $row): array => CatalogNormalizer::category($row[0]), $rows)];
            if ($id !== null) {
                $data['breadcrumbs'] = array_map(
                    static fn (Category $category): array => ['id' => $category->id()->value(), 'name' => $category->name()],
                    $tree->breadcrumbs($id),
                );
            }
            $context->json($data);

            return ExitCode::OK;
        }

        if ($id !== null) {
            $context->text('Путь: ' . implode(' › ', array_map(static fn (Category $c): string => $c->name(), $tree->breadcrumbs($id))) . "\n\n");
        }
        $context->text(Table::render(['ID', 'Категория', 'Офферов'], array_map(static fn (array $row): array => [
            (string) $row[0]->id()->value(),
            str_repeat('  ', $row[1]) . $row[0]->name() . ($row[0]->isArchived() ? ' (архив)' : ''),
            $row[0]->offerCount() === null ? null : (string) $row[0]->offerCount(),
        ], $rows)));

        return ExitCode::OK;
    }

    /**
     * @param list<array{Category, int}> $rows
     */
    private static function walk(CategoryTree $tree, Category $category, int $level, ?int $depth, array &$rows): void
    {
        $rows[] = [$category, $level];
        if ($depth !== null && $level >= $depth) {
            return;
        }
        foreach ($tree->childrenOf($category->id()) as $child) {
            self::walk($tree, $child, $level + 1, $depth, $rows);
        }
    }
}
