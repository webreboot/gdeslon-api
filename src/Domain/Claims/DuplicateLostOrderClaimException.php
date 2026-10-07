<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\GdeSlonException;

final class DuplicateLostOrderClaimException extends \RuntimeException implements GdeSlonException
{
    public function __construct(private readonly LostOrderClaim $existing)
    {
        parent::__construct(sprintf('Заявка на этот заказ уже есть: №%d — новая не отправлена', $existing->id()->value()));
    }

    public function existing(): LostOrderClaim
    {
        return $this->existing;
    }
}
