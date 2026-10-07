<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Тариф магазина — ставка вознаграждения за целевое действие: процент от суммы заказа или фиксированная сумма.
 *
 * Ставка хранится строкой как в API («10.31», «200.0»): это значение для показа и сравнения, а не для денежных
 * расчётов; валюту фиксированной ставки API не указывает.
 */
final class Tariff
{
    private readonly ?string $title;

    /**
     * @param list<string> $trafficCategories коды типов трафика, для которых действует тариф (пусто — без ограничения)
     * @param list<string> $productCategories категории товаров магазина, для которых действует тариф
     */
    public function __construct(
        private readonly string $id,
        ?string $title,
        private readonly RateType $rateType,
        private readonly string $rate,
        private readonly array $trafficCategories = [],
        private readonly array $productCategories = [],
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Пустой ID тарифа');
        }
        DecimalRate::assert($rate, 'Тариф ' . $id);
        foreach ([...$trafficCategories, ...$productCategories] as $item) {
            if (trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Тариф %s: пустой код типа трафика или категории товаров', $id));
            }
        }

        $this->title = $title === null || trim($title) === '' ? null : $title;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function rateType(): RateType
    {
        return $this->rateType;
    }

    public function isPercent(): bool
    {
        return $this->rateType === RateType::Percent;
    }

    public function rate(): string
    {
        return $this->rate;
    }

    /**
     * @return list<string>
     */
    public function trafficCategories(): array
    {
        return $this->trafficCategories;
    }

    /**
     * @return list<string>
     */
    public function productCategories(): array
    {
        return $this->productCategories;
    }
}
