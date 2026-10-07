<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Критерии выборки заказов (docs/gdeslon-api/orders.md). Неизменяемые; ошибки — до запроса: на неверный фильтр API
 * отвечает 500 без подробностей, а неизвестные поля молча игнорирует.
 *
 * Период — `days` дней, заканчивающихся днём `until` включительно, по дате `dateField`. `until` null — «сегодня» по
 * Москве (определяет адаптер). Новые параметры конструктора добавляются только в конец (вызывайте с именами).
 */
final class OrderCriteria
{
    public const DEFAULT_DAYS = 30;

    /** Ограничение библиотеки (≈ 10 лет), а не API: пагинации нет, весь период приходит одним ответом. */
    public const MAX_DAYS = 3660;

    private readonly ?string $until;

    private readonly ?MerchantId $merchant;

    /** @var list<OrderState> */
    private readonly array $states;

    private readonly ?string $subId;

    /**
     * @param \DateTimeInterface|string|null $until    последний день периода: «2026-10-07» или дата (берётся её день в её
     *                                                 часовом поясе); null — сегодня
     * @param int                            $days     длина периода в днях, 1..3660
     * @param int|MerchantId|null            $merchant один магазин (несколько в одном запросе API не принимает)
     * @param list<OrderState|int>           $states   любой из статусов; пусто — все
     */
    public function __construct(
        private readonly OrderDateField $dateField = OrderDateField::Created,
        \DateTimeInterface|string|null $until = null,
        private readonly int $days = self::DEFAULT_DAYS,
        int|MerchantId|null $merchant = null,
        array $states = [],
        private readonly ?OrderType $type = null,
        ?string $subId = null,
    ) {
        $this->until = $until === null ? null : self::date($until);

        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('days должен быть от 1 до %d, получено %d', self::MAX_DAYS, $days));
        }

        $this->merchant = is_int($merchant) ? new MerchantId($merchant) : $merchant;
        $this->states = self::normalizeStates($states);
        $this->subId = $subId === null ? null : self::normalizeSubId($subId);
    }

    public function dateField(): OrderDateField
    {
        return $this->dateField;
    }

    /**
     * Последний день периода «Y-m-d»; null — сегодня.
     */
    public function until(): ?string
    {
        return $this->until;
    }

    public function days(): int
    {
        return $this->days;
    }

    public function merchant(): ?MerchantId
    {
        return $this->merchant;
    }

    /**
     * @return list<OrderState>
     */
    public function states(): array
    {
        return $this->states;
    }

    public function type(): ?OrderType
    {
        return $this->type;
    }

    public function subId(): ?string
    {
        return $this->subId;
    }

    private static function date(\DateTimeInterface|string $until): string
    {
        if ($until instanceof \DateTimeInterface) {
            return $until->format('Y-m-d');
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $until);
        if ($parsed === false || $parsed->format('Y-m-d') !== $until) {
            throw new InvalidArgumentException(sprintf('Дата «%s»: ожидался существующий день в формате Y-m-d', $until));
        }

        return $until;
    }

    /**
     * @param list<OrderState|int> $states
     *
     * @return list<OrderState>
     */
    private static function normalizeStates(array $states): array
    {
        $unique = [];
        foreach ($states as $state) {
            $state = self::state($state);
            $unique[$state->value] ??= $state;
        }

        return array_values($unique);
    }

    /**
     * mixed — тип проверяется во время выполнения: статусы часто приходят строками из форм и CLI.
     */
    private static function state(mixed $state): OrderState
    {
        if ($state instanceof OrderState) {
            return $state;
        }
        if (!is_int($state)) {
            throw new InvalidArgumentException(sprintf('Неверный статус: ожидался OrderState или int, получен %s', get_debug_type($state)));
        }

        return OrderState::tryFrom($state)
            ?? throw new InvalidArgumentException(sprintf('Неизвестный статус %d, ожидался 0..4', $state));
    }

    private static function normalizeSubId(string $subId): string
    {
        if (preg_match('//u', $subId) !== 1) {
            throw new InvalidArgumentException('sub_id: строка не в UTF-8');
        }
        $subId = trim($subId);
        if ($subId === '') {
            throw new InvalidArgumentException('Пустой sub_id — не задавайте его, чтобы не фильтровать');
        }

        return $subId;
    }
}
