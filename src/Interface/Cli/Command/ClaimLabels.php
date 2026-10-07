<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;

/**
 * @internal
 */
final class ClaimLabels
{
    private function __construct()
    {
    }

    public static function orderStatus(LostOrderStatus $status): string
    {
        return match ($status) {
            LostOrderStatus::Waiting => 'ожидает',
            LostOrderStatus::Confirmed => 'подтверждён',
            LostOrderStatus::Declined => 'отклонён',
        };
    }

    public static function claimState(LostOrderClaimState $state): string
    {
        return match ($state) {
            LostOrderClaimState::InWork => 'в работе',
            LostOrderClaimState::Closed => 'закрыта',
        };
    }

    /**
     * @return list<array{string, string|null}>
     */
    public static function details(LostOrderClaim $claim): array
    {
        return [
            ['ID заявки', (string) $claim->id()->value()],
            ['Номер заказа', $claim->orderNumber()],
            ['Дата заказа', $claim->orderDate()->format('Y-m-d')],
            ['Сумма', $claim->orderTotal()->amount()],
            ['Магазин', trim(sprintf('%d %s', $claim->merchantId()->value(), $claim->merchantName() ?? ''))],
            ['Статус заказа', self::orderStatus($claim->orderStatus())],
            ['Заявка', self::claimState($claim->claimState())],
            ['Обновлено', $claim->orderUpdatedAt()?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i')],
            ['Описание', $claim->description()],
            ['Чек', $claim->attachmentUrl()],
        ];
    }
}
