<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\Json;
use Webreboot\GdeSlon\Interface\Mcp\NotFoundException;
use Webreboot\GdeSlon\Interface\Mcp\Tool\Tool;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;

/**
 * Инструмент для тестов протокола: возвращает value; особые значения — ошибки и пограничные ответы.
 */
final class EchoTool implements Tool
{
    public function __construct(private readonly bool $exposesToken = false)
    {
    }

    public function name(): string
    {
        return 'echo';
    }

    public function title(): string
    {
        return 'Эхо';
    }

    public function description(): string
    {
        return 'Возвращает value.';
    }

    public function inputProperties(): array
    {
        return ['value' => Json::object(['type' => 'string'])];
    }

    public function required(): array
    {
        return [];
    }

    public function outputShape(): array
    {
        return ['value' => 'string|null'];
    }

    public function credentials(): Credentials
    {
        return Credentials::None;
    }

    public function exposesToken(): bool
    {
        return $this->exposesToken;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $value = $arguments->string('value', 100);
        $arguments->done();

        return match ($value) {
            'inf' => ['value' => INF],
            'huge' => ['value' => str_repeat('x', 500000)],
            'boom' => throw new \RuntimeException('boom secret-token-0123'),
            'failed' => throw new HttpException('HTTP 500 secret-token-0123', 500, 'GET', 'https://h/', ''),
            'access' => throw new AuthenticationException('HTTP 401', 401, 'GET', 'https://h/', ''),
            'not_found' => throw new NotFoundException('Записи 5 нет'),
            default => ['value' => $value],
        };
    }
}
