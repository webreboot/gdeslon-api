<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;

/**
 * @internal
 */
final class ListMerchantCategoriesTool extends ReadTool
{
    public function name(): string
    {
        return 'list_merchant_categories';
    }

    public function title(): string
    {
        return 'Категории магазинов';
    }

    public function description(): string
    {
        return 'Категории магазинов (для list_merchants.merchant_category_id) — не товарные категории get_categories.';
    }

    public function outputShape(): array
    {
        return ['categories' => ['[]' => ['id' => 'int', 'name' => 'string|null']]];
    }

    public function credentials(): Credentials
    {
        return Credentials::TokenOptional;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $arguments->done();

        return ['categories' => array_map(CatalogNormalizer::merchantCategory(...), $context->gdeslon()->merchants()->categories())];
    }
}
