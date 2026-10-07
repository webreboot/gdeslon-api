<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Критерии списка заявок. Неизменяемые; ошибки — до запроса. Магазин и статусы дополнительно проверяются на клиенте
 * (matches()): документированные фильтры статусов API принимает с любым значением, работают ли они — не проверено.
 * Новые параметры конструктора добавляются только в конец (вызывайте с именами).
 */
final class LostOrderCriteria
{
    private readonly ?MerchantId $merchant;

    private readonly ?string $from;

    private readonly ?string $until;

    /**
     * @param \DateTimeInterface|string|null $from  с дня «Y-m-d» (по какой дате фильтрует API — не документировано)
     * @param \DateTimeInterface|string|null $until по день «Y-m-d»
     */
    public function __construct(
        int|MerchantId|null $merchant = null,
        \DateTimeInterface|string|null $from = null,
        \DateTimeInterface|string|null $until = null,
        private readonly ?LostOrderClaimState $claimState = null,
        private readonly ?LostOrderStatus $orderStatus = null,
    ) {
        $this->merchant = is_int($merchant) ? new MerchantId($merchant) : $merchant;
        $this->from = $from === null ? null : self::date($from);
        $this->until = $until === null ? null : self::date($until);
        if ($this->from !== null && $this->until !== null && $this->from > $this->until) {
            throw new InvalidArgumentException(sprintf('Начало периода %s позже конца %s', $this->from, $this->until));
        }
    }

    public function merchant(): ?MerchantId
    {
        return $this->merchant;
    }

    public function from(): ?string
    {
        return $this->from;
    }

    public function until(): ?string
    {
        return $this->until;
    }

    public function claimState(): ?LostOrderClaimState
    {
        return $this->claimState;
    }

    public function orderStatus(): ?LostOrderStatus
    {
        return $this->orderStatus;
    }

    /**
     * Подходит ли заявка под магазин и статусы (даты проверяет только API).
     */
    public function matches(LostOrderClaim $claim): bool
    {
        return ($this->merchant === null || $this->merchant->equals($claim->merchantId()))
            && ($this->claimState === null || $this->claimState === $claim->claimState())
            && ($this->orderStatus === null || $this->orderStatus === $claim->orderStatus());
    }

    private static function date(\DateTimeInterface|string $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf('Дата «%s»: ожидался существующий день в формате Y-m-d', $date));
        }

        return $date;
    }
}
