<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Normalizer;

use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;

/**
 * @internal
 */
final class ClaimsNormalizer
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function claim(LostOrderClaim $claim): array
    {
        return [
            'id' => $claim->id()->value(),
            'order_number' => $claim->orderNumber(),
            'order_date' => ValueNormalizer::date($claim->orderDate()),
            'order_total' => $claim->orderTotal()->amount(),
            'merchant_id' => $claim->merchantId()->value(),
            'merchant_name' => $claim->merchantName(),
            'order_status' => self::orderStatus($claim->orderStatus()),
            'claim_state' => self::claimState($claim->claimState()),
            'description' => $claim->description(),
            'attachment_url' => $claim->attachmentUrl(),
            'order_updated_at' => ValueNormalizer::moment($claim->orderUpdatedAt()),
        ];
    }

    /**
     * @return array{claims: list<array<string, mixed>>, skipped: list<string>}
     */
    public static function claimList(LostOrderClaimList $list): array
    {
        return ['claims' => array_map(self::claim(...), $list->all()), 'skipped' => $list->skipped()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function newClaim(NewLostOrderClaim $claim): array
    {
        return [
            'order_number' => $claim->orderNumber(),
            'order_date' => $claim->orderDate(),
            'order_total' => $claim->orderTotal()->amount(),
            'merchant_id' => $claim->merchant()->value(),
            'description' => $claim->description(),
            'attachment' => [
                'file_name' => $claim->attachment()->fileName(),
                'type' => $claim->attachment()->type()->value,
                'size' => $claim->attachment()->size(),
            ],
        ];
    }

    public static function orderStatus(LostOrderStatus $status): string
    {
        return match ($status) {
            LostOrderStatus::Waiting => 'waiting',
            LostOrderStatus::Confirmed => 'confirmed',
            LostOrderStatus::Declined => 'declined',
        };
    }

    public static function claimState(LostOrderClaimState $state): string
    {
        return match ($state) {
            LostOrderClaimState::InWork => 'in_work',
            LostOrderClaimState::Closed => 'closed',
        };
    }
}
