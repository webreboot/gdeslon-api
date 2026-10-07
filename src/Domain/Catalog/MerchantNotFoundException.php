<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * В списке нет магазина с таким ID (в том числе для невозможных ID вроде 0).
 */
final class MerchantNotFoundException extends \OutOfBoundsException implements GdeSlonException
{
    private function __construct(string $message, private readonly int $merchantId)
    {
        parent::__construct($message);
    }

    public static function forId(int|MerchantId $id): self
    {
        $value = $id instanceof MerchantId ? $id->value() : $id;

        return new self(sprintf('Магазин %d не найден', $value), $value);
    }

    public function merchantId(): int
    {
        return $this->merchantId;
    }
}
