<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\GdeSlonException;

final class CategoryNotFoundException extends \OutOfBoundsException implements GdeSlonException
{
    private function __construct(string $message, private readonly int $categoryId)
    {
        parent::__construct($message);
    }

    public static function forId(int|CategoryId $id): self
    {
        $value = $id instanceof CategoryId ? $id->value() : $id;

        return new self(sprintf('Категория %d не найдена', $value), $value);
    }

    public function categoryId(): int
    {
        return $this->categoryId;
    }
}
