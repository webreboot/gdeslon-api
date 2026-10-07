<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\UsageException;
use Webreboot\GdeSlon\Interface\Mcp\Protocol;
use Webreboot\GdeSlon\Interface\Mcp\ProtocolVersion;
use Webreboot\GdeSlon\Interface\Mcp\Server;
use Webreboot\GdeSlon\Interface\Mcp\StdioChannel;
use Webreboot\GdeSlon\Interface\Mcp\ToolCatalog;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;

/**
 * @internal
 */
final class McpCommand extends BaseCommand
{
    public function name(): string
    {
        return 'mcp';
    }

    public function summary(): string
    {
        return 'MCP-сервер для AI-агентов (stdio, только чтение)';
    }

    public function help(): string
    {
        return "Читает JSON-RPC из stdin и отвечает в stdout, пока stdin открыт. Запускается клиентом (Claude Desktop,\n"
            . "Claude Code…), а не вручную. Ключи — из окружения или --env-file; без ключей доступны категории и\n"
            . 'публичный каталог магазинов. Подключение и инструменты — docs/mcp.md.';
    }

    public function options(): array
    {
        return [Option::flag('reveal-links', 'настоящие ссылки купонов с токеном XML API (попадут в контекст агента)')];
    }

    public function credentials(): Credentials
    {
        return Credentials::None;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        if ($input->has('format')) {
            throw new UsageException('«--format» у mcp не используется: ответы — всегда JSON-RPC');
        }
        $reveal = $input->flag('reveal-links');
        $environment = $context->environment;
        $tools = new ToolContext(static fn (): GdeSlon => $context->gdeslon(), $context->clock, $environment, $context->console, $reveal);

        $tools->log(sprintf(
            'MCP-сервер gdeslon-api %s (протокол %s); токен XML API — %s, ключи API по продажам — %s',
            GdeSlon::VERSION,
            implode(', ', ProtocolVersion::supported()),
            $environment->token() === null ? 'не задан' : 'задан',
            $environment->userId() === null || $environment->apiKey() === null ? 'не заданы' : 'заданы',
        ));
        if ($reveal) {
            $tools->log('--reveal-links: ссылки купонов содержат ваш токен XML API и попадут в контекст и логи агента');
        }

        [$in, $out] = $context->console->protocolStreams();

        return (new Server(new StdioChannel($in, $out), new Protocol(ToolCatalog::standard($reveal), $tools), $tools))->run();
    }
}
