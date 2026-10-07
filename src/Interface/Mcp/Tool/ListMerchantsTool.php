<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\Page;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;

/**
 * @internal
 */
final class ListMerchantsTool extends ReadTool
{
    public function name(): string
    {
        return 'list_merchants';
    }

    public function title(): string
    {
        return 'Магазины';
    }

    public function description(): string
    {
        return 'Магазины (рекламодатели), короткая форма. С GDESLON_API_TOKEN на сервере — магазины вебмастера с партнёрскими '
            . 'ссылками, без него — публичный каталог. Фильтры складываются. Условия, тарифы и трафик — get_merchant.';
    }

    public function inputProperties(): array
    {
        return [
            'search' => self::text('подстрока в названии или домене', 100),
            'domain' => self::text('сайт магазина: хост или URL', 255),
            'merchant_category_id' => self::id('ID категории магазинов (list_merchant_categories), не товарной'),
            ...Page::inputSchema(20, 100),
        ];
    }

    public function outputShape(): array
    {
        return ['merchants' => ['[]' => JsonShapes::MERCHANT_SUMMARY], 'skipped' => ['[]' => 'string']] + self::PAGE_SHAPE;
    }

    public function credentials(): Credentials
    {
        return Credentials::TokenOptional;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $search = $arguments->string('search', 100);
        $domain = $arguments->string('domain', 255);
        $category = $arguments->int('merchant_category_id', 1);
        $page = Page::of($arguments, 20, 100);
        $arguments->done();

        $list = $context->gdeslon()->merchants();
        $merchants = $list->all();
        if ($search !== null) {
            $merchants = self::intersect($merchants, $list->search($search));
        }
        if ($domain !== null) {
            $merchants = self::intersect($merchants, $list->findByDomain($domain));
        }
        if ($category !== null) {
            $merchants = self::intersect($merchants, $list->inCategory($category));
        }

        return [
            'merchants' => array_map(CatalogNormalizer::merchantSummary(...), $page->slice($merchants)),
            'skipped' => $list->skipped(),
        ] + $page->meta(count($merchants));
    }

    /**
     * @param list<Merchant> $merchants
     * @param list<Merchant> $matching
     *
     * @return list<Merchant>
     */
    private static function intersect(array $merchants, array $matching): array
    {
        $ids = array_map(static fn (Merchant $m): int => $m->id()->value(), $matching);

        return array_values(array_filter($merchants, static fn (Merchant $m): bool => in_array($m->id()->value(), $ids, true)));
    }
}
