<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Domain\Catalog\CategoryNotFoundException;
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Interface\Catalog\Subcategories;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\Json;
use Webreboot\GdeSlon\Interface\Mcp\NotFoundException;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;

/**
 * @internal
 */
final class SearchOffersTool extends ReadTool
{
    public const SORTS = ['price' => OfferSort::Price, 'partner_benefit' => OfferSort::PartnerBenefit, 'newest' => OfferSort::Newest];

    public function name(): string
    {
        return 'search_offers';
    }

    public function title(): string
    {
        return 'Поиск товаров';
    }

    public function description(): string
    {
        return 'Товары (офферы) с партнёрскими ссылками. query — слова как в API: «iphone OR samsung», минус-слова «iphone -pink». '
            . 'category_ids ищет только в самих категориях, без подкатегорий; для раздела целиком — include_subcategories: true. '
            . 'Страницы — page (не глубже 10 000 офферов: page × limit), next_page — номер следующей. Нужен GDESLON_API_TOKEN.';
    }

    public function inputProperties(): array
    {
        return [
            'query' => self::text('ключевые слова', 500),
            'merchant_ids' => self::ids('только в этих магазинах'),
            'exclude_merchant_ids' => self::ids('кроме этих магазинов'),
            'category_ids' => self::ids('только в этих товарных категориях (get_categories)'),
            'exclude_category_ids' => self::ids('кроме этих товарных категорий'),
            'include_subcategories' => self::flag('category_ids и exclude_category_ids вместе со всеми подкатегориями (без флага — только сами категории); исключение побеждает'),
            'articles' => Json::object(['type' => 'array', 'items' => self::text('артикул', 255), 'maxItems' => 100, 'description' => 'артикулы товаров']),
            'limit' => self::int('офферов на странице', 1, SearchCriteria::MAX_LIMIT, SearchCriteria::DEFAULT_LIMIT),
            'page' => self::int('номер страницы с 1', 1, 10000, 1),
            'sort' => self::enum('порядок', array_keys(self::SORTS)),
            'parked_domain' => self::text('припаркованный домен для партнёрских ссылок', 255),
        ];
    }

    public function outputShape(): array
    {
        return [
            'query' => 'string|null',
            'page' => 'int',
            'limit' => 'int',
            'total' => 'int|null',
            'next_page' => 'int|null',
            'offers' => ['[]' => JsonShapes::OFFER],
            'skipped' => ['[]' => 'string'],
        ];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $query = $arguments->string('query', 500);
        $merchants = $arguments->intList('merchant_ids');
        $excludedMerchants = $arguments->intList('exclude_merchant_ids');
        $categories = $arguments->intList('category_ids');
        $excludedCategories = $arguments->intList('exclude_category_ids');
        $includeSubcategories = $arguments->bool('include_subcategories') ?? false;
        $articles = $arguments->stringList('articles', 255);
        $limit = $arguments->int('limit', 1, SearchCriteria::MAX_LIMIT);
        $page = $arguments->int('page', 1);
        $sort = $arguments->choice('sort', self::SORTS);
        $parkedDomain = $arguments->string('parked_domain', 255);
        $arguments->done();

        $subcategories = $includeSubcategories && ($categories !== [] || $excludedCategories !== []);
        $criteria = new SearchCriteria(
            query: $query,
            merchants: $merchants,
            excludedMerchants: $excludedMerchants,
            categories: $subcategories ? [] : $categories,
            excludedCategories: $subcategories ? [] : $excludedCategories,
            articles: $articles,
            limit: $limit ?? SearchCriteria::DEFAULT_LIMIT,
            page: $page ?? 1,
            sort: $sort,
            parkedDomain: $parkedDomain,
        );

        if ($subcategories) {
            try {
                $criteria = Subcategories::expand($context->gdeslon()->categories(), $criteria, $categories, $excludedCategories);
            } catch (CategoryNotFoundException $e) {
                throw new NotFoundException(sprintf('Категории %d нет', $e->categoryId()), 0, $e);
            }
        }

        return CatalogNormalizer::searchResult($context->gdeslon()->search($criteria));
    }
}
