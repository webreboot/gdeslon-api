<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\GdeSlonException;

interface LostOrderClaims
{
    /**
     * @throws GdeSlonException
     */
    public function find(LostOrderCriteria $criteria): LostOrderClaimList;

    /**
     * @throws GdeSlonException
     */
    public function get(LostOrderClaimId $id): ?LostOrderClaim;

    /**
     * @throws LostOrderValidationException
     * @throws LostOrderClaimUnconfirmedException
     * @throws GdeSlonException
     */
    public function submit(NewLostOrderClaim $claim): LostOrderClaim;
}
