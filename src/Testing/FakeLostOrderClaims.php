<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Testing;

use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaims;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;

final class FakeLostOrderClaims implements LostOrderClaims
{
    /** @var list<LostOrderClaim> */
    private array $claims;

    /** @var list<LostOrderCriteria> */
    private array $criteria = [];

    /** @var list<NewLostOrderClaim> */
    private array $submitted = [];

    private ?\Throwable $submitFailure = null;

    /**
     * @param list<LostOrderClaim> $claims
     * @param list<string>         $skipped
     */
    public function __construct(#[\SensitiveParameter] array $claims = [], private readonly array $skipped = [])
    {
        $this->claims = $claims;
    }

    public function find(LostOrderCriteria $criteria): LostOrderClaimList
    {
        $this->criteria[] = $criteria;

        return new LostOrderClaimList($criteria, array_values(array_filter($this->claims, $criteria->matches(...))), $this->skipped);
    }

    public function get(LostOrderClaimId $id): ?LostOrderClaim
    {
        foreach ($this->claims as $claim) {
            if ($claim->id()->equals($id)) {
                return $claim;
            }
        }

        return null;
    }

    public function submit(#[\SensitiveParameter] NewLostOrderClaim $claim): LostOrderClaim
    {
        if ($this->submitFailure !== null) {
            throw $this->submitFailure;
        }
        $orderDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $claim->orderDate(), new \DateTimeZone('Europe/Moscow'));
        if ($orderDate === false) {
            throw new \LogicException('FakeLostOrderClaims: неразборная дата заказа');
        }
        $this->submitted[] = $claim;
        $created = new LostOrderClaim(
            max([9000, ...array_map(static fn (LostOrderClaim $existing): int => $existing->id()->value(), $this->claims)]) + 1,
            $claim->orderNumber(),
            $orderDate,
            $claim->orderTotal(),
            $claim->merchant(),
            LostOrderStatus::Waiting,
            LostOrderClaimState::InWork,
        );
        $this->claims[] = $created;

        return $created;
    }

    public function failSubmitWith(\Throwable $failure): self
    {
        $this->submitFailure = $failure;

        return $this;
    }

    /**
     * @return list<LostOrderCriteria>
     */
    public function criteria(): array
    {
        return $this->criteria;
    }

    /**
     * @return list<NewLostOrderClaim>
     */
    public function submitted(): array
    {
        return $this->submitted;
    }
}
