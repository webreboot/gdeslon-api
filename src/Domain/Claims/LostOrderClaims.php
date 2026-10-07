<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Заявки вебмастера на потерянные заказы.
 */
interface LostOrderClaims
{
    /**
     * @throws GdeSlonException
     */
    public function find(LostOrderCriteria $criteria): LostOrderClaimList;

    /**
     * @return LostOrderClaim|null null — заявки нет (или она чужая)
     *
     * @throws GdeSlonException
     */
    public function get(LostOrderClaimId $id): ?LostOrderClaim;

    /**
     * Создаёт РЕАЛЬНУЮ заявку у рекламодателя. Не повторяется автоматически.
     *
     * @throws LostOrderValidationException       заявка отклонена (не создана)
     * @throws LostOrderClaimUnconfirmedException исход неизвестен — заявка могла быть создана
     * @throws GdeSlonException
     */
    public function submit(NewLostOrderClaim $claim): LostOrderClaim;
}
