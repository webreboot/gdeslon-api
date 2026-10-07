<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * Создание заявки не подтверждено. Не повторяйте вслепую — сначала проверьте lostOrders().
 *
 * - wasCreated() = true: сервер ответил 2xx (заявка создана), но ответ не разобран; claimId() — её ID, если он есть в
 *   ответе. Повторная отправка создаст дубль.
 * - wasCreated() = false: запрос ушёл, но ответа нет или он 5xx (таймаут, обрыв) — заявка МОГЛА быть создана; сервер
 *   может ещё обрабатывать запрос, поэтому проверяйте lostOrders() не сразу.
 *
 * Причина — getPrevious().
 */
final class LostOrderClaimUnconfirmedException extends \RuntimeException implements GdeSlonException
{
    /**
     * @param int|null $createdStatus 2xx-статус ответа, если заявка создана, но ответ не разобран
     */
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

    /**
     * Сервер подтвердил создание (2xx), но ответ не разобран.
     */
    public function wasCreated(): bool
    {
        return $this->createdStatus !== null;
    }

    /**
     * ID созданной заявки, если он есть в неразобранном ответе.
     */
    public function claimId(): ?int
    {
        return $this->claimId;
    }
}
