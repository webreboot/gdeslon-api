<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Конверсия — уведомление «Где Слон?» о заказе или лиде при смене статуса (postback). Неизменяемая.
 *
 * Обязательны только магазин и статус (тестовая отправка из кабинета требует лишь их). Суммы — десятичные строки
 * «как прислал «Где Слон?»», без Money: к какой валюте относятся `profit` и `order_sum`, не документировано
 * (`currency` — валюта конверсии, `priceInCurrency` — сумма в ней). Все значения — недоверенный ввод из запроса.
 * Новые параметры конструктора добавляются только в конец (вызывайте с именами).
 */
final class Conversion
{
    public const MAX_SUB_IDS = 5;

    private readonly MerchantId $merchantId;

    private readonly ?OrderId $orderId;

    private readonly ?string $merchantOrderNumber;

    /** @var array<int, string> */
    private readonly array $subIds;

    private readonly ?string $userAgent;

    private readonly ?string $offerName;

    private readonly ?string $clickId;

    /**
     * @param OrderId|string|null $orderId             ID заказа в «Где Слон?» (`gs_order_id`)
     * @param string|null         $merchantOrderNumber номер заказа у магазина (`order_id`)
     * @param array<int, string>  $subIds              sub_id по номерам 1..5; пустые отбрасываются
     * @param string|null         $reward              заработок вебмастера (`profit`), «123.45»
     * @param string|null         $orderSum            сумма заказа (`order_sum`)
     * @param string|null         $priceInCurrency     сумма в валюте `currency` (`price_in_currency`)
     */
    public function __construct(
        int|MerchantId $merchantId,
        private readonly OrderState $state,
        OrderId|string|null $orderId = null,
        ?string $merchantOrderNumber = null,
        array $subIds = [],
        private readonly ?string $reward = null,
        private readonly ?string $orderSum = null,
        private readonly ?string $priceInCurrency = null,
        private readonly ?string $currency = null,
        private readonly ?\DateTimeImmutable $clickedAt = null,
        private readonly ?\DateTimeImmutable $actionAt = null,
        ?string $userAgent = null,
        ?string $offerName = null,
        ?string $clickId = null,
    ) {
        $this->merchantId = is_int($merchantId) ? new MerchantId($merchantId) : $merchantId;
        $this->orderId = is_string($orderId) ? new OrderId($orderId) : $orderId;

        foreach (['reward' => $reward, 'orderSum' => $orderSum, 'priceInCurrency' => $priceInCurrency] as $name => $amount) {
            if ($amount !== null && preg_match('/^\d+(\.\d+)?\z/', $amount) !== 1) {
                throw new InvalidArgumentException(sprintf('%s: ожидалась неотрицательная сумма вида «123.45»', $name));
            }
        }
        if ($currency !== null && preg_match('/^[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('Код валюты должен состоять из трёх заглавных латинских букв');
        }

        $this->subIds = self::normalizeSubIds($subIds);
        $this->merchantOrderNumber = self::text($merchantOrderNumber);
        $this->userAgent = self::text($userAgent);
        $this->offerName = self::text($offerName);
        $this->clickId = self::text($clickId);
    }

    public function merchantId(): MerchantId
    {
        return $this->merchantId;
    }

    public function state(): OrderState
    {
        return $this->state;
    }

    /**
     * ID заказа в «Где Слон?».
     */
    public function orderId(): ?OrderId
    {
        return $this->orderId;
    }

    /**
     * Номер заказа у магазина.
     */
    public function merchantOrderNumber(): ?string
    {
        return $this->merchantOrderNumber;
    }

    /**
     * sub_id по номеру: 1 — `sub_id`, 2..5 — `sub_id2`..`sub_id5`.
     */
    public function subId(int $position = 1): ?string
    {
        self::assertSubIdPosition($position);

        return $this->subIds[$position] ?? null;
    }

    /**
     * @return array<int, string> непустые sub_id по номерам 1..5
     */
    public function subIds(): array
    {
        return $this->subIds;
    }

    /**
     * Заработок вебмастера, десятичная строка.
     */
    public function reward(): ?string
    {
        return $this->reward;
    }

    public function orderSum(): ?string
    {
        return $this->orderSum;
    }

    public function priceInCurrency(): ?string
    {
        return $this->priceInCurrency;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    /**
     * Время перехода по партнёрской ссылке.
     */
    public function clickedAt(): ?\DateTimeImmutable
    {
        return $this->clickedAt;
    }

    /**
     * Время создания заказа у магазина.
     */
    public function actionAt(): ?\DateTimeImmutable
    {
        return $this->actionAt;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function offerName(): ?string
    {
        return $this->offerName;
    }

    public function clickId(): ?string
    {
        return $this->clickId;
    }

    /**
     * Ключ «заказ + статус» для защиты от повторной обработки одного уведомления: `gdeslon:<ID заказа>:<статус>`, без ID
     * — `merchant:<магазин>:<номер у магазина>:<статус>`; null — заказ не определить.
     */
    public function deduplicationKey(): ?string
    {
        if ($this->orderId !== null) {
            return sprintf('gdeslon:%s:%d', $this->orderId, $this->state->value);
        }
        if ($this->merchantOrderNumber !== null) {
            return sprintf('merchant:%d:%s:%d', $this->merchantId->value(), $this->merchantOrderNumber, $this->state->value);
        }

        return null;
    }

    /**
     * @param array<int, string> $subIds
     *
     * @return array<int, string>
     */
    private static function normalizeSubIds(array $subIds): array
    {
        $normalized = [];
        foreach ($subIds as $position => $value) {
            self::assertSubIdPosition($position);
            if (preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('sub_id%s: строка не в UTF-8', $position === 1 ? '' : $position));
            }
            $value = trim($value);
            if ($value !== '') {
                $normalized[$position] = $value;
            }
        }
        ksort($normalized);

        return $normalized;
    }

    private static function assertSubIdPosition(int $position): void
    {
        if ($position < 1 || $position > self::MAX_SUB_IDS) {
            throw new InvalidArgumentException(sprintf('Номер sub_id должен быть от 1 до %d, получено %d', self::MAX_SUB_IDS, $position));
        }
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
