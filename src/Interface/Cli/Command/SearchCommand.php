<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Catalog\CategoryNotFoundException;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Interface\Catalog\Subcategories;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\OptionValues;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;

/**
 * @internal
 */
final class SearchCommand extends BaseCommand
{
    private const SORTS = ['price' => OfferSort::Price, 'partner-benefit' => OfferSort::PartnerBenefit, 'newest' => OfferSort::Newest];

    public function name(): string
    {
        return 'search';
    }

    public function arguments(): string
    {
        return '[<запрос>…]';
    }

    public function summary(): string
    {
        return 'поиск товаров с партнёрскими ссылками';
    }

    public function help(): string
    {
        return "Запрос — слова через пробел, как в API: «iphone OR samsung», минус-слова — после «--»:\n"
            . "  gdeslon search -- iphone -pink\n"
            . 'Поиск отдаёт не глубже 10 000 офферов (--page × --limit).';
    }

    public function options(): array
    {
        return [
            Option::listOf('merchant', 'только в этих магазинах'),
            Option::listOf('exclude-merchant', 'кроме этих магазинов'),
            Option::listOf('category', 'только в этих товарных категориях (gdeslon categories)'),
            Option::listOf('exclude-category', 'кроме этих категорий'),
            Option::flag('subcategories', '--category и --exclude-category вместе с подкатегориями; исключение побеждает'),
            Option::listOf('article', 'артикулы товаров', 'АРТИКУЛ,…'),
            Option::value('limit', 'офферов на странице, 1–100 (по умолчанию 10)', 'N'),
            Option::value('page', 'номер страницы, с 1', 'N'),
            Option::value('sort', 'порядок: price, partner-benefit, newest', 'ПОРЯДОК'),
            Option::value('parked-domain', 'припаркованный домен для партнёрских ссылок', 'URL'),
        ];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        $sort = $input->value('sort');
        $categories = self::intList($input, 'category');
        $excludedCategories = self::intList($input, 'exclude-category');
        $subcategories = $input->flag('subcategories') && ($categories !== [] || $excludedCategories !== []);
        $criteria = new SearchCriteria(
            query: $arguments === [] ? null : implode(' ', $arguments),
            merchants: self::intList($input, 'merchant'),
            excludedMerchants: self::intList($input, 'exclude-merchant'),
            categories: $subcategories ? [] : $categories,
            excludedCategories: $subcategories ? [] : $excludedCategories,
            articles: $input->list('article'),
            limit: self::intOption($input, 'limit') ?? SearchCriteria::DEFAULT_LIMIT,
            page: self::intOption($input, 'page') ?? 1,
            sort: $sort === null ? null : OptionValues::choice($sort, self::SORTS, 'sort'),
            parkedDomain: $input->value('parked-domain'),
        );

        if ($subcategories) {
            try {
                $criteria = Subcategories::expand($context->categories(), $criteria, $categories, $excludedCategories);
            } catch (CategoryNotFoundException $e) {
                $context->notice(sprintf('Категории %d нет', $e->categoryId()));

                return ExitCode::FAILURE;
            }
        }

        $result = $context->gdeslon()->search($criteria);
        $context->warnSkipped($result->skipped());
        if ($context->json) {
            $context->json(CatalogNormalizer::searchResult($result));

            return ExitCode::OK;
        }
        $next = $result->nextPage();
        if ($result->isEmpty() && $next === null) {
            $context->nothingFound();

            return ExitCode::OK;
        }

        if (!$result->isEmpty()) {
            $context->text(Table::render(['Магазин', 'Товар', 'Цена', 'Вознагр.', 'Ссылка'], array_map(static fn (Offer $offer): array => [
                (string) $offer->merchantId()->value(),
                $offer->name(),
                (string) $offer->price(),
                $offer->charge() === null ? null : (string) $offer->charge(),
                $offer->affiliateLink(),
            ], $result->offers()), [1 => 50]) . "\n");
        }
        $context->text(sprintf(
            "Страница %d · найдено %s · %s%s\n",
            $criteria->page(),
            $result->total() === null ? '?' : (string) $result->total(),
            $result->isDegraded() ? sprintf('загружено частями по %d · ', $result->effectiveLimit()) : '',
            $next === null ? 'последняя страница' : sprintf('следующая: --page=%d', $next->page()),
        ));

        return ExitCode::OK;
    }
}
