<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Mcp;

use PHPUnit\Framework\TestCase;

/**
 * `bin/gdeslon mcp` отдельным процессом, как его запускает клиент: в stdout — только JSON-RPC, по строке на ответ,
 * даже при display_errors=1. Без сети: initialize, tools/list и ping не обращаются к API.
 */
final class McpBinTest extends TestCase
{
    public function testStdoutIsProtocolOnly(): void
    {
        $messages = [
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            '{"jsonrpc":"2.0","id":3,"method":"ping"}',
        ];
        $command = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', dirname(__DIR__, 4) . '/bin/gdeslon', 'mcp', '--no-cache'];
        $env = ['PATH' => (string) getenv('PATH'), 'HOME' => sys_get_temp_dir()];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        /** @var array{resource, resource, resource} $pipes */
        [$stdin, $out, $err] = $pipes;
        fwrite($stdin, implode("\n", $messages) . "\n");
        fclose($stdin);
        $stdout = (string) stream_get_contents($out);
        $stderr = (string) stream_get_contents($err);
        fclose($out);
        fclose($err);

        self::assertSame(0, proc_close($process), $stderr);
        $lines = explode("\n", rtrim($stdout, "\n"));
        self::assertCount(3, $lines, $stdout);
        foreach ($lines as $i => $line) {
            $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($response);
            self::assertSame('2.0', $response['jsonrpc']);
            self::assertSame($i + 1, $response['id']);
            self::assertArrayHasKey('result', $response);
        }
        foreach (['Warning', 'Notice', 'Deprecated'] as $noise) {
            self::assertStringNotContainsString($noise, $stdout . $stderr);
        }
        self::assertSame(1, preg_match('//u', $stderr), 'stderr в UTF-8');
        self::assertStringContainsString('MCP-сервер', $stderr);
    }
}
