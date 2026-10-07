<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaims;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;

/**
 * Заявки без сети: список — заданные заявки (и пропуски), submit запоминает команду и возвращает синтетическую заявку.
 */
final class FakeLostOrderClaims implements LostOrderClaims
{
    /** @var list<LostOrderCriteria> */
    public array $criteria = [];

    /** @var list<NewLostOrderClaim> */
    public array $submitted = [];

    private ?\Throwable $submitFailure = null;

    /**
     * @param list<LostOrderClaim> $claims
     * @param list<string>         $skipped
     */
    public function __construct(private readonly array $claims = [], private readonly array $skipped = [])
    {
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

    /**
     * Следующий submit бросит это исключение (заявка в submitted не попадёт).
     */
    public function failSubmitWith(\Throwable $failure): void
    {
        $this->submitFailure = $failure;
    }

    public function submit(NewLostOrderClaim $claim): LostOrderClaim
    {
        if ($this->submitFailure !== null) {
            throw $this->submitFailure;
        }
        $this->submitted[] = $claim;

        return new LostOrderClaim(
            9000 + count($this->submitted),
            $claim->orderNumber(),
            new \DateTimeImmutable($claim->orderDate()),
            $claim->orderTotal(),
            $claim->merchant(),
            LostOrderStatus::Waiting,
            LostOrderClaimState::InWork,
        );
    }
}
