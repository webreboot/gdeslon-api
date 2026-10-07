<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use PHPUnit\Framework\Assert;
use Webreboot\GdeSlon\Domain\Shared\Clock;

/**
 * `gdeslon mcp` в тесте: сообщения JSON-RPC — в stdin CliTester, ответы — по строке из stdout.
 */
final class McpTester
{
    public const LEGACY = '2025-11-25';

    /** @var list<array<string, mixed>> */
    public array $responses = [];

    public int $exitCode = 0;

    public readonly CliTester $cli;

    public function __construct(FakeHttpTransport $transport = new FakeHttpTransport(), ?Clock $clock = null, ?\Closure $build = null)
    {
        $this->cli = new CliTester($transport, $clock, $build);
    }

    /**
     * @param list<array<string, mixed>|string> $messages сообщения (массив кодируется в JSON) или готовые строки
     * @param array<string, string>              $env
     * @param list<string>                       $argv
     *
     * @return list<array<string, mixed>>
     */
    public function send(array $messages, array $env = [], array $argv = ['mcp', '--no-cache']): array
    {
        $lines = array_map(static fn (array|string $m): string => is_string($m) ? $m : (string) json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $messages);
        $this->exitCode = $this->cli->run($argv, $env, implode("\n", $lines) . "\n");

        $this->responses = [];
        foreach (explode("\n", rtrim($this->cli->stdout, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            Assert::assertIsArray($decoded, $line);
            /** @var array<string, mixed> $decoded */
            $this->responses[] = $decoded;
        }

        return $this->responses;
    }

    /**
     * Рукопожатие и один tools/call; результат вызова.
     *
     * @param array<string, mixed>  $arguments
     * @param array<string, string> $env
     * @param list<string>          $argv
     *
     * @return array<string, mixed>
     */
    public function call(string $tool, array $arguments = [], array $env = [], array $argv = ['mcp', '--no-cache']): array
    {
        $this->send([
            self::initialize(),
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]],
        ], $env, $argv);
        Assert::assertSame(0, $this->exitCode, $this->cli->stderr);
        Assert::assertCount(2, $this->responses, $this->cli->stdout);

        return self::result($this->responses[1]);
    }

    /**
     * structuredContent успешного вызова (и проверка, что текст — тот же JSON).
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function structured(array $result): array
    {
        Assert::assertArrayNotHasKey('isError', $result, self::text($result));
        Assert::assertIsArray($result['structuredContent'] ?? null);
        Assert::assertSame($result['structuredContent'], json_decode(self::text($result), true, 512, JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> */
        return $result['structuredContent'];
    }

    /**
     * Текст ошибки инструмента (isError).
     *
     * @param array<string, mixed> $result
     */
    public static function failure(array $result): string
    {
        Assert::assertTrue($result['isError'] ?? false, self::text($result));
        Assert::assertArrayNotHasKey('structuredContent', $result);

        return self::text($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function text(array $result): string
    {
        Assert::assertIsArray($result['content'] ?? null);
        Assert::assertIsArray($result['content'][0] ?? null);
        Assert::assertSame('text', $result['content'][0]['type'] ?? null);
        Assert::assertIsString($result['content'][0]['text'] ?? null);

        return $result['content'][0]['text'];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public static function result(array $response): array
    {
        Assert::assertArrayNotHasKey('error', $response, (string) json_encode($response['error'] ?? null, JSON_UNESCAPED_UNICODE));
        Assert::assertIsArray($response['result'] ?? null);

        /** @var array<string, mixed> */
        return $response['result'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function initialize(string $version = self::LEGACY, int $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize', 'params' => [
            'protocolVersion' => $version,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]];
    }
}
