<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\GdeSlonException;

final class LostOrderClaimUnconfirmedException extends \RuntimeException implements GdeSlonException
{
    public function __construct(\Throwable $previous, private readonly ?int $createdStatus = null, private readonly ?int $claimId = null)
    {
        parent::__construct(
            $createdStatus === null
                ? 'Не удалось подтвердить создание заявки — она могла быть создана; проверьте lostOrders() перед повтором: ' . $previous->getMessage()
                : sprintf(
                    'Заявка создана (HTTP %d%s), но ответ не разобран — не отправляйте её повторно, проверьте lostOrders(): %s',
                    $createdStatus,
                    $claimId === null ? '' : ', ID ' . $claimId,
                    $previous->getMessage(),
                ),
            0,
            $previous,
        );
    }

    public function wasCreated(): bool
    {
        return $this->createdStatus !== null;
    }

    public function claimId(): ?int
    {
        return $this->claimId;
    }
}
