<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class LostOrderCriteria
{
    private readonly ?MerchantId $merchant;

    private readonly ?string $from;

    private readonly ?string $until;

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
