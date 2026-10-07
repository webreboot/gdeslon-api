<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class Tariff
{
    private readonly ?string $title;

    /**
     * @param list<string> $trafficCategories
     * @param list<string> $productCategories
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
