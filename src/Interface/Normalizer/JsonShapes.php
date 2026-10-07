<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Normalizer;

/**
 * @internal
 */
final class JsonShapes
{
    public const MONEY = ['amount' => 'string', 'currency' => 'string'];

    public const CATEGORY = [
        'id' => 'int',
        'parent_id' => 'int|null',
        'name' => 'string',
        'archived' => 'bool',
        'path' => ['[]' => 'int'],
        'depth' => 'int',
        'offer_count' => 'int|null',
    ];

    public const MERCHANT = [
        'id' => 'int',
        'name' => 'string',
        'url' => 'string',
        'domain' => 'string',
        'short_description' => 'string',
        'description' => 'string',
        'conditions' => 'string',
        'logo_url' => 'string|null',
        'country' => 'string|null',
        'kind' => 'string|null',
        'green' => 'bool',
        'commission_summary' => 'string|null',
        'categories' => ['[]' => ['id' => 'int', 'name' => 'string|null']],
        'affiliate_link' => 'string|null',
        'traffic_types' => ['[]' => ['name' => 'string', 'allowed' => 'bool']],
        'tariffs' => ['[]' => [
            'id' => 'string',
            'title' => 'string|null',
            'rate_type' => 'string',
            'rate' => 'string',
            'traffic_categories' => ['[]' => 'string'],
            'product_categories' => ['[]' => 'string'],
        ]],
        'category_tariffs' => ['[]' => ['merchant_category_id' => 'int', 'name' => 'string|null', 'rate_type' => 'string', 'rate' => 'string']],
        'ad_marking' => 'string|null',
    ];

    public const OFFER = [
        'id' => 'string',
        'merchant_id' => 'int',
        'name' => 'string',
        'price' => self::MONEY,
        'old_price' => ['?' => self::MONEY],
        'charge' => ['?' => self::MONEY],
        'affiliate_link' => 'string',
        'article' => 'string|null',
        'category_id' => 'int|null',
        'available' => 'bool',
        'picture' => 'string|null',
        'thumbnail' => 'string|null',
        'original_picture' => 'string|null',
        'description' => 'string|null',
        'vendor' => 'string|null',
        'model' => 'string|null',
        'product_url' => 'string|null',
        'ad_marking' => 'string|null',
    ];

    public const ORDER = [
        'id' => 'string',
        'merchant_id' => 'int',
        'merchant_name' => 'string|null',
        'state' => 'string',
        'type' => 'string',
        'reward' => self::MONEY,
        'amount' => ['?' => self::MONEY],
        'merchant_order_number' => 'string|null',
        'sub_id' => 'string|null',
        'affiliate_id' => 'int|null',
        'item_count' => 'int|null',
        'transition_at' => 'string|null',
        'created_at' => 'string|null',
        'last_updated_at' => 'string|null',
        'confirmed_at' => 'string|null',
        'accrued_at' => 'string|null',
        'keywords' => 'string|null',
    ];

    public const CLAIM = [
        'id' => 'int',
        'order_number' => 'string',
        'order_date' => 'string',
        'order_total' => 'string',
        'merchant_id' => 'int',
        'merchant_name' => 'string|null',
        'order_status' => 'string',
        'claim_state' => 'string',
        'description' => 'string|null',
        'attachment_url' => 'string|null',
        'order_updated_at' => 'string|null',
    ];

    public const NEW_CLAIM = [
        'order_number' => 'string',
        'order_date' => 'string',
        'order_total' => 'string',
        'merchant_id' => 'int',
        'description' => 'string|null',
        'attachment' => ['file_name' => 'string', 'type' => 'string', 'size' => 'int'],
    ];

    public const COUPON_KIND = ['id' => 'int|null', 'name' => 'string'];

    public const COUPON = [
        'id' => 'int',
        'merchant_id' => 'int',
        'merchant_name' => 'string|null',
        'name' => 'string',
        'description' => 'string',
        'instruction' => 'string|null',
        'code' => 'string|null',
        'kind' => self::COUPON_KIND,
        'categories' => ['[]' => ['id' => 'int', 'name' => 'string|null']],
        'starts_at' => 'string',
        'ends_at' => 'string',
        'affiliate_link' => 'string',
        'affiliate_link_with_code' => 'string|null',
        'ad_marking' => 'string|null',
    ];

    public const MERCHANT_SUMMARY = [
        'id' => 'int',
        'name' => 'string',
        'domain' => 'string',
        'url' => 'string',
        'commission_summary' => 'string|null',
        'categories' => ['[]' => ['id' => 'int', 'name' => 'string|null']],
        'affiliate_link' => 'string|null',
        'ad_marking' => 'string|null',
    ];

    private function __construct()
    {
    }
}
