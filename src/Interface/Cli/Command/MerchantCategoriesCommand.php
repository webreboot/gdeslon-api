<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Catalog\MerchantCategory;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;

/**
 * @internal
 */
final class MerchantCategoriesCommand extends BaseCommand
{
    public function name(): string
    {
        return 'merchants categories';
    }

    public function summary(): string
    {
        return 'категории магазинов (для merchants --category)';
    }

    public function credentials(): Credentials
    {
        return Credentials::TokenOptional;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        $categories = $context->gdeslon()->merchants()->categories();

        if ($context->json) {
            $context->json(['categories' => array_map(CatalogNormalizer::merchantCategory(...), $categories)]);

            return ExitCode::OK;
        }
        if ($categories === []) {
            $context->nothingFound();

            return ExitCode::OK;
        }
        $context->text(Table::render(['ID', 'Категория'], array_map(
            static fn (MerchantCategory $category): array => [(string) $category->id(), $category->name()],
            $categories,
        )));

        return ExitCode::OK;
    }
}
