<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class OfferKey implements \Stringable
{
    private const SEPARATOR = ':';

    private readonly string $article;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private readonly MerchantId $merchantId, string $article)
    {
        if (self::isBlank($article)) {
            throw new InvalidArgumentException(sprintf('Ключ оффера магазина %s: пустой артикул', $merchantId));
        }

        $this->article = $article;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $key): self
    {
        $parts = explode(self::SEPARATOR, $key, 2);
        if (count($parts) !== 2 || preg_match('/^\d{1,18}\z/', $parts[0]) !== 1 || (int) $parts[0] === 0 || self::isBlank($parts[1])) {
            throw new InvalidArgumentException('Ключ оффера: ожидалось «<ID магазина>:<артикул>»');
        }

        return new self(new MerchantId((int) $parts[0]), $parts[1]);
    }

    public function merchantId(): MerchantId
    {
        return $this->merchantId;
    }

    public function article(): string
    {
        return $this->article;
    }

    public function equals(self $other): bool
    {
        return $this->merchantId->equals($other->merchantId) && $this->article === $other->article;
    }

    public function __toString(): string
    {
        return $this->merchantId . self::SEPARATOR . $this->article;
    }

    private static function isBlank(string $value): bool
    {
        return preg_match('/^[\s\x{00A0}]*\z/u', $value) === 1;
    }
}
