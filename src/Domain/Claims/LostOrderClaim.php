<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;

final class LostOrderClaim
{
    private readonly LostOrderClaimId $id;

    private readonly string $orderNumber;

    private readonly MerchantId $merchantId;

    private readonly ?string $merchantName;

    private readonly ?string $description;

    private readonly ?string $attachmentUrl;

    public function __construct(
        int|LostOrderClaimId $id,
        string $orderNumber,
        private readonly \DateTimeImmutable $orderDate,
        private readonly OrderTotal $orderTotal,
        int|MerchantId $merchantId,
        private readonly LostOrderStatus $orderStatus,
        private readonly LostOrderClaimState $claimState,
        ?string $merchantName = null,
        ?string $description = null,
        ?string $attachmentUrl = null,
        private readonly ?\DateTimeImmutable $orderUpdatedAt = null,
    ) {
        $this->id = is_int($id) ? new LostOrderClaimId($id) : $id;
        $this->orderNumber = trim($orderNumber);
        $this->merchantId = is_int($merchantId) ? new MerchantId($merchantId) : $merchantId;
        $this->merchantName = self::text($merchantName);
        $this->description = self::text($description);
        $this->attachmentUrl = self::text($attachmentUrl);
    }

    public function id(): LostOrderClaimId
    {
        return $this->id;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function orderDate(): \DateTimeImmutable
    {
        return $this->orderDate;
    }

    public function orderTotal(): OrderTotal
    {
        return $this->orderTotal;
    }

    public function merchantId(): MerchantId
    {
        return $this->merchantId;
    }

    public function merchantName(): ?string
    {
        return $this->merchantName;
    }

    public function orderStatus(): LostOrderStatus
    {
        return $this->orderStatus;
    }

    public function claimState(): LostOrderClaimState
    {
        return $this->claimState;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function attachmentUrl(): ?string
    {
        return $this->attachmentUrl;
    }

    public function orderUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->orderUpdatedAt;
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
