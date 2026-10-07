<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;

/**
 * Инструмент MCP. Имя, аргументы, ключи результата и теги ошибок — публичный контракт (docs/mcp.md); классы —
 * внутренние.
 *
 * @internal
 */
interface Tool
{
    /**
     * `verb_noun`, `[A-Za-z0-9_]`.
     */
    public function name(): string;

    public function title(): string;

    public function description(): string;

    /**
     * JSON-схемы аргументов по именам (inputSchema.properties).
     *
     * @return array<string, \stdClass>
     */
    public function inputProperties(): array;

    /**
     * @return list<string>
     */
    public function required(): array;

    /**
     * Форма structuredContent в нотации JsonShapes (из неё строится outputSchema).
     *
     * @return array<string, mixed>
     */
    public function outputShape(): array;

    public function credentials(): Credentials;

    /**
     * Результат может содержать токен XML API намеренно (ссылки купонов при --reveal-links) — его не маскировать.
     */
    public function exposesToken(): bool;

    /**
     * Читает аргументы, вызывает $arguments->done() до обращения к API и возвращает structuredContent.
     *
     * @return array<string, mixed>
     */
    public function call(Arguments $arguments, ToolContext $context): array;
}
