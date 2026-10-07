<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/**
 * Параметр postback пришёл не строкой (JSON-массив, объект, true/false, вложенный XML-элемент).
 *
 * @internal
 */
final class NonScalarValue
{
    public function __construct(private readonly string $type)
    {
    }

    public function type(): string
    {
        return $this->type;
    }
}
