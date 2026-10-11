<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class Money
{
    public function __construct(private readonly string $amount, private readonly string $currency)
    {
        if (preg_match('/^\d+(\.\d+)?\z/', $amount) !== 1) {
            throw new InvalidArgumentException(sprintf('Сумма «%s» — не неотрицательное десятичное число', $amount));
        }
        if (preg_match('/^[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException(sprintf('Код валюты «%s» — не три заглавные латинские буквы', $currency));
        }
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function isZero(): bool
    {
        return Decimal::normalize($this->amount) === '0';
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && Decimal::normalize($this->amount) === Decimal::normalize($other->amount);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function plus(#[\SensitiveParameter] self $other): self
    {
        $this->assertSameCurrency($other->currency);

        return new self(Decimal::add($this->amount, $other->amount), $this->currency);
    }

    /**
     * @return -1|0|1
     *
     * @throws InvalidArgumentException
     */
    public function compare(#[\SensitiveParameter] self $other): int
    {
        $this->assertSameCurrency($other->currency);

        return Decimal::compare($this->amount, $other->amount);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function isGreaterThan(#[\SensitiveParameter] self $other): bool
    {
        return $this->compare($other) === 1;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function isLessThan(#[\SensitiveParameter] self $other): bool
    {
        return $this->compare($other) === -1;
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }

    private function assertSameCurrency(string $currency): void
    {
        if ($currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf('Суммы в разных валютах: %s и %s', $this->currency, $currency));
        }
    }
}
