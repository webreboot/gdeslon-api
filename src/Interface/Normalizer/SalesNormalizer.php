<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Normalizer;

use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;

/**
 * Заказы → JSON. Статусы и типы — явными таблицами: новый case в домене ломает phpstan, а не JSON молча.
 *
 * @internal
 */
final class SalesNormalizer
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(Order $order): array
    {
        return [
            'id' => $order->id()->value(),
            'merchant_id' => $order->merchantId()->value(),
            'merchant_name' => $order->merchantName(),
            'state' => self::state($order->state()),
            'type' => self::type($order->type()),
            'reward' => ValueNormalizer::money($order->reward()),
            'amount' => ValueNormalizer::money($order->amount()),
            'merchant_order_number' => $order->merchantOrderNumber(),
            'sub_id' => $order->subId(),
            'affiliate_id' => $order->affiliateId(),
            'item_count' => $order->itemCount(),
            'transition_at' => ValueNormalizer::moment($order->transitionAt()),
            'created_at' => ValueNormalizer::moment($order->createdAt()),
            'last_updated_at' => ValueNormalizer::moment($order->lastUpdatedAt()),
            'confirmed_at' => ValueNormalizer::moment($order->confirmedAt()),
            'accrued_at' => ValueNormalizer::moment($order->accruedAt()),
            'keywords' => $order->keywords(),
        ];
    }

    /**
     * @return array{orders: list<array<string, mixed>>, skipped: list<string>}
     */
    public static function orderList(OrderList $list): array
    {
        return ['orders' => array_map(self::order(...), $list->all()), 'skipped' => $list->skipped()];
    }

    public static function state(OrderState $state): string
    {
        return match ($state) {
            OrderState::New => 'new',
            OrderState::Cancelled => 'cancelled',
            OrderState::Pending => 'pending',
            OrderState::Confirmed => 'confirmed',
            OrderState::Paid => 'paid',
        };
    }

    public static function type(OrderType $type): string
    {
        return match ($type) {
            OrderType::Product => 'product',
            OrderType::Lead => 'lead',
        };
    }
}
