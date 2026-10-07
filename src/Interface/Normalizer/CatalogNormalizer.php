<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Normalizer;

use Webreboot\GdeSlon\Domain\Catalog\Category;
use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTariff;
use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Domain\Catalog\MerchantCategory;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\RateType;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Domain\Catalog\Tariff;
use Webreboot\GdeSlon\Domain\Catalog\TrafficType;

/**
 * Каталог → JSON: категории, магазины, результаты поиска.
 *
 * @internal
 */
final class CatalogNormalizer
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(Category $category): array
    {
        return [
            'id' => $category->id()->value(),
            'parent_id' => $category->parentId()?->value(),
            'name' => $category->name(),
            'archived' => $category->isArchived(),
            'path' => array_map(static fn (CategoryId $id): int => $id->value(), $category->path()),
            'depth' => $category->depth(),
            'offer_count' => $category->offerCount(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function merchant(Merchant $merchant): array
    {
        return [
            'id' => $merchant->id()->value(),
            'name' => $merchant->name(),
            'url' => $merchant->url(),
            'domain' => $merchant->domain(),
            'short_description' => $merchant->shortDescription(),
            'description' => $merchant->description(),
            'conditions' => $merchant->conditions(),
            'logo_url' => $merchant->logoUrl(),
            'country' => $merchant->country(),
            'kind' => $merchant->kind(),
            'green' => $merchant->isGreen(),
            'commission_summary' => $merchant->commissionSummary(),
            'categories' => array_map(self::merchantCategory(...), $merchant->categories()),
            'affiliate_link' => $merchant->affiliateLink(),
            'traffic_types' => array_map(
                static fn (TrafficType $type): array => ['name' => $type->name(), 'allowed' => $type->isAllowed()],
                $merchant->trafficTypes(),
            ),
            'tariffs' => array_map(static fn (Tariff $tariff): array => [
                'id' => $tariff->id(),
                'title' => $tariff->title(),
                'rate_type' => self::rateType($tariff->rateType()),
                'rate' => $tariff->rate(),
                'traffic_categories' => $tariff->trafficCategories(),
                'product_categories' => $tariff->productCategories(),
            ], $merchant->tariffs()),
            'category_tariffs' => array_map(static fn (CategoryTariff $tariff): array => [
                'merchant_category_id' => $tariff->merchantCategoryId(),
                'name' => $tariff->name(),
                'rate_type' => $tariff->isPercent() ? 'percent' : 'fixed',
                'rate' => $tariff->rate(),
            ], $merchant->categoryTariffs()),
            'ad_marking' => $merchant->adMarking(),
        ];
    }

    /**
     * @return array{merchants: list<array<string, mixed>>, skipped: list<string>}
     */
    public static function merchantList(MerchantList $list): array
    {
        return ['merchants' => array_map(self::merchant(...), $list->all()), 'skipped' => $list->skipped()];
    }

    /**
     * @return array{id: int, name: string|null}
     */
    public static function merchantCategory(MerchantCategory $category): array
    {
        return ['id' => $category->id(), 'name' => $category->name()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function offer(Offer $offer): array
    {
        return [
            'id' => $offer->id(),
            'merchant_id' => $offer->merchantId()->value(),
            'name' => $offer->name(),
            'price' => ValueNormalizer::money($offer->price()),
            'old_price' => ValueNormalizer::money($offer->oldPrice()),
            'charge' => ValueNormalizer::money($offer->charge()),
            'affiliate_link' => $offer->affiliateLink(),
            'article' => $offer->article(),
            'category_id' => $offer->categoryId()?->value(),
            'available' => $offer->isAvailable(),
            'picture' => $offer->picture(),
            'thumbnail' => $offer->thumbnail(),
            'original_picture' => $offer->originalPicture(),
            'description' => $offer->description(),
            'vendor' => $offer->vendor(),
            'model' => $offer->model(),
            'product_url' => $offer->productUrl(),
            'ad_marking' => $offer->adMarking(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function searchResult(SearchResult $result): array
    {
        $criteria = $result->criteria();

        return [
            'query' => $criteria->query(),
            'page' => $criteria->page(),
            'limit' => $criteria->limit(),
            'total' => $result->total(),
            'next_page' => $result->nextPage()?->page(),
            'offers' => array_map(self::offer(...), $result->offers()),
            'skipped' => $result->skipped(),
        ];
    }

    public static function rateType(RateType $type): string
    {
        return match ($type) {
            RateType::Percent => 'percent',
            RateType::Fixed => 'fixed',
        };
    }

    /**
     * @param list<MerchantId> $ids
     *
     * @return list<int>
     */
    public static function merchantIds(array $ids): array
    {
        return array_map(static fn (MerchantId $id): int => $id->value(), $ids);
    }
}
