<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class Order
{
    private readonly OrderId $id;

    private readonly MerchantId $merchantId;

    private readonly ?string $merchantOrderNumber;

    private readonly ?string $subId;

    private readonly ?string $merchantName;

    private readonly ?string $keywords;

    public function __construct(
        OrderId|string $id,
        int|MerchantId $merchantId,
        private readonly OrderState $state,
        private readonly OrderType $type,
        private readonly Money $reward,
        private readonly ?Money $amount = null,
        ?string $merchantOrderNumber = null,
        ?string $subId = null,
        ?string $merchantName = null,
        private readonly ?int $affiliateId = null,
        private readonly ?int $itemCount = null,
        private readonly ?\DateTimeImmutable $transitionAt = null,
        private readonly ?\DateTimeImmutable $createdAt = null,
        private readonly ?\DateTimeImmutable $lastUpdatedAt = null,
        private readonly ?\DateTimeImmutable $confirmedAt = null,
        private readonly ?\DateTimeImmutable $accruedAt = null,
        ?string $keywords = null,
    ) {
        $this->id = is_string($id) ? new OrderId($id) : $id;
        $this->merchantId = is_int($merchantId) ? new MerchantId($merchantId) : $merchantId;

        if ($amount !== null && $amount->currency() !== $reward->currency()) {
            throw new InvalidArgumentException(sprintf(
                'Заказ %s: сумма в %s, а вознаграждение в %s',
                $this->id,
                $amount->currency(),
                $reward->currency(),
            ));
        }
        if ($itemCount !== null && $itemCount < 0) {
            throw new InvalidArgumentException(sprintf('Заказ %s: количество товаров меньше нуля (%d)', $this->id, $itemCount));
        }
        if ($affiliateId !== null && $affiliateId <= 0) {
            throw new InvalidArgumentException(sprintf('Заказ %s: ID вебмастера должен быть положительным', $this->id));
        }

        $this->merchantOrderNumber = self::text($merchantOrderNumber);
        $this->subId = self::text($subId);
        $this->merchantName = self::text($merchantName);
        $this->keywords = self::text($keywords);
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function merchantId(): MerchantId
    {
        return $this->merchantId;
    }

    public function state(): OrderState
    {
        return $this->state;
    }

    public function type(): OrderType
    {
        return $this->type;
    }

    public function isLead(): bool
    {
        return $this->type === OrderType::Lead;
    }

    public function reward(): Money
    {
        return $this->reward;
    }

    public function amount(): ?Money
    {
        return $this->amount;
    }

    public function merchantOrderNumber(): ?string
    {
        return $this->merchantOrderNumber;
    }

    public function subId(): ?string
    {
        return $this->subId;
    }

    public function merchantName(): ?string
    {
        return $this->merchantName;
    }

    public function affiliateId(): ?int
    {
        return $this->affiliateId;
    }

    public function itemCount(): ?int
    {
        return $this->itemCount;
    }

    public function transitionAt(): ?\DateTimeImmutable
    {
        return $this->transitionAt;
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->lastUpdatedAt;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function accruedAt(): ?\DateTimeImmutable
    {
        return $this->accruedAt;
    }

    public function keywords(): ?string
    {
        return $this->keywords;
    }

    public function date(OrderDateField $field): ?\DateTimeImmutable
    {
        return match ($field) {
            OrderDateField::Transition => $this->transitionAt,
            OrderDateField::Created => $this->createdAt,
            OrderDateField::LastUpdated => $this->lastUpdatedAt,
            OrderDateField::Confirmed => $this->confirmedAt,
            OrderDateField::Accrued => $this->accruedAt,
        };
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
