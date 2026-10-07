<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Новая заявка на потерянный заказ (FAQ 74). Создание отправляет РЕАЛЬНУЮ заявку рекламодателю — см.
 * GdeSlon::submitLostOrderClaim(). Ограничения API проверяются до запроса: номер заказа ≤ 255 символов, сумма с двумя
 * знаками, описание ≤ 255 символов, чек JPEG/PNG/PDF ≤ 10 МиБ, дата заказа — за последние 3 месяца (assertOrderDateWithin).
 */
final class NewLostOrderClaim
{
    private const MAX_TEXT = 255;

    private readonly string $orderNumber;

    private readonly string $orderDate;

    private readonly OrderTotal $orderTotal;

    private readonly MerchantId $merchant;

    private readonly ?string $description;

    /**
     * @param string                    $orderNumber номер заказа у магазина
     * @param \DateTimeInterface|string $orderDate   «Y-m-d» или дата (берётся её день в её часовом поясе)
     */
    public function __construct(
        string $orderNumber,
        \DateTimeInterface|string $orderDate,
        OrderTotal|string|int|float $orderTotal,
        int|MerchantId $merchant,
        private readonly ClaimAttachment $attachment,
        ?string $description = null,
    ) {
        self::assertUtf8($orderNumber, 'Номер заказа');
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '' || preg_match('/[\x00-\x1F\x7F]/', $orderNumber) === 1) {
            throw new InvalidArgumentException('Номер заказа пуст или содержит управляющие символы');
        }
        self::assertLength($orderNumber, 'Номер заказа');

        $this->orderNumber = $orderNumber;
        $this->orderDate = self::date($orderDate);
        $this->orderTotal = $orderTotal instanceof OrderTotal ? $orderTotal : OrderTotal::of($orderTotal);
        $this->merchant = is_int($merchant) ? new MerchantId($merchant) : $merchant;

        if ($description !== null) {
            self::assertUtf8($description, 'Описание');
            $description = trim(str_replace("\r\n", "\n", $description));
            self::assertLength($description, 'Описание');
        }
        $this->description = $description === '' ? null : $description;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    /**
     * «Y-m-d».
     */
    public function orderDate(): string
    {
        return $this->orderDate;
    }

    public function orderTotal(): OrderTotal
    {
        return $this->orderTotal;
    }

    public function merchant(): MerchantId
    {
        return $this->merchant;
    }

    public function attachment(): ClaimAttachment
    {
        return $this->attachment;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * Дата заказа — не в будущем и не старше 3 календарных месяцев от $today (от 31 мая — с последнего дня февраля).
     *
     * @param \DateTimeImmutable $today сегодня (его день — в его часовом поясе; API работает по Москве)
     */
    public function assertOrderDateWithin(\DateTimeImmutable $today): void
    {
        $todayDate = $today->format('Y-m-d');
        $year = (int) $today->format('Y');
        $month = (int) $today->format('n') - 3;
        if ($month < 1) {
            $month += 12;
            $year--;
        }
        $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $day = min((int) $today->format('j'), $daysInMonth);
        $earliest = sprintf('%04d-%02d-%02d', $year, $month, $day);

        if ($this->orderDate > $todayDate || $this->orderDate < $earliest) {
            throw new InvalidArgumentException(sprintf(
                'Дата заказа %s вне окна подачи заявки: с %s по %s (не старше 3 месяцев)',
                $this->orderDate,
                $earliest,
                $todayDate,
            ));
        }
    }

    private static function date(\DateTimeInterface|string $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf('Дата заказа «%s»: ожидался существующий день в формате Y-m-d', $date));
        }

        return $date;
    }

    private static function assertUtf8(string $value, string $what): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s: строка не в UTF-8', $what));
        }
    }

    private static function assertLength(string $value, string $what): void
    {
        if (preg_match('/^.{0,' . self::MAX_TEXT . '}\z/us', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s длиннее %d символов', $what, self::MAX_TEXT));
        }
    }
}
