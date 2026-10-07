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
        return $this->normalized() === '0';
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->normalized() === $other->normalized();
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }

    private function normalized(): string
    {
        $parts = explode('.', $this->amount, 2);
        $integer = ltrim($parts[0], '0');
        $fraction = rtrim($parts[1] ?? '', '0');

        return ($integer === '' ? '0' : $integer) . ($fraction === '' ? '' : '.' . $fraction);
    }
}
