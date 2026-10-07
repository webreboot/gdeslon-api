<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Interface\Mcp\Json;

/**
 * Инструмент чтения: значения по умолчанию и построители JSON-схем аргументов.
 *
 * @internal
 */
abstract class ReadTool implements Tool
{
    /** Поля страницы списка (Page::meta). */
    protected const PAGE_SHAPE = ['total' => 'int', 'limit' => 'int', 'offset' => 'int', 'next_offset' => 'int|null'];

    public function inputProperties(): array
    {
        return [];
    }

    public function required(): array
    {
        return [];
    }

    public function exposesToken(): bool
    {
        return false;
    }

    protected static function id(string $description): \stdClass
    {
        return Json::object(['type' => 'integer', 'minimum' => 1, 'description' => $description]);
    }

    protected static function ids(string $description): \stdClass
    {
        return Json::object(['type' => 'array', 'items' => Json::object(['type' => 'integer', 'minimum' => 1]), 'maxItems' => 100, 'description' => $description]);
    }

    protected static function text(string $description, int $maxLength): \stdClass
    {
        return Json::object(['type' => 'string', 'minLength' => 1, 'maxLength' => $maxLength, 'description' => $description]);
    }

    protected static function int(string $description, int $min, int $max, int $default): \stdClass
    {
        return Json::object(['type' => 'integer', 'minimum' => $min, 'maximum' => $max, 'default' => $default, 'description' => $description]);
    }

    /**
     * @param list<string> $values
     */
    protected static function enum(string $description, array $values): \stdClass
    {
        return Json::object(['type' => 'string', 'enum' => $values, 'description' => $description]);
    }

    protected static function date(string $description): \stdClass
    {
        return Json::object(['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => $description . ' (ГГГГ-ММ-ДД)']);
    }

    protected static function flag(string $description): \stdClass
    {
        return Json::object(['type' => 'boolean', 'default' => false, 'description' => $description]);
    }
}
