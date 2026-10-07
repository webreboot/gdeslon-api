<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Mcp;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryRepository;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;
use Webreboot\GdeSlon\Interface\Cli\Application;
use Webreboot\GdeSlon\Interface\Cli\Console;
use Webreboot\GdeSlon\Tests\Support\CliTester;
use Webreboot\GdeSlon\Tests\Support\FailingStream;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;
use Webreboot\GdeSlon\Tests\Support\McpTester;

final class McpServerTest extends TestCase
{
    private const TOKEN = ['GDESLON_API_TOKEN' => 'secret-token-0123'];

    private const READ_TOOLS = [
        'get_categories', 'list_merchants', 'get_merchant', 'list_merchant_categories', 'search_offers', 'list_orders',
        'list_lost_order_claims', 'get_lost_order_claim', 'list_coupons', 'get_coupon', 'list_coupon_kinds',
    ];

    public function testHandshakeAndToolsListWithoutSdk(): void
    {
        $mcp = new McpTester();
        $mcp->send([
            McpTester::initialize(),
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'],
        ], self::TOKEN);

        self::assertSame(0, $mcp->exitCode, $mcp->cli->stderr);
        self::assertSame([1, 2, 3], array_column($mcp->responses, 'id'));
        self::assertSame(0, $mcp->cli->factoryCalls, 'initialize и tools/list без SDK и сети');
        self::assertStringContainsString('MCP', $mcp->cli->stderr, 'строка запуска — в stderr');
        self::assertStringContainsString('токен XML API — задан', $mcp->cli->stderr);
        self::assertStringNotContainsString('secret-token-0123', $mcp->cli->stderr);

        $tools = McpTester::result($mcp->responses[1])['tools'];
        self::assertIsArray($tools);
        self::assertSame(self::READ_TOOLS, array_column($tools, 'name'));
        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            self::assertNotSame('', $tool['title'] ?? '');
            self::assertNotSame('', $tool['description'] ?? '');
            self::assertIsArray($tool['inputSchema']);
            self::assertSame('object', $tool['inputSchema']['type']);
            self::assertFalse($tool['inputSchema']['additionalProperties']);
            foreach (['anyOf', 'oneOf', 'allOf', '$ref'] as $combinator) {
                self::assertArrayNotHasKey($combinator, $tool['inputSchema']);
            }
            self::assertIsArray($tool['outputSchema']);
            self::assertSame('object', $tool['outputSchema']['type']);
            self::assertIsArray($tool['annotations']);
            self::assertTrue($tool['annotations']['readOnlyHint']);
            self::assertTrue($tool['annotations']['openWorldHint']);
        }
    }

    public function testToolsListIsByteStable(): void
    {
        $mcp = new McpTester();
        $mcp->send([McpTester::initialize(), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);
        $lines = explode("\n", rtrim($mcp->cli->stdout));

        self::assertCount(2, $lines);
        self::assertStringContainsString('"name":"list_merchant_categories","title":"Категории магазинов","description":', $lines[1]);
        self::assertStringContainsString('"inputSchema":{"type":"object","properties":{},"additionalProperties":false}', $lines[1], 'без аргументов — properties {}');
        self::assertStringNotContainsString('"properties":[]', $lines[1]);
    }

    public function testRevealLinksIsToldToTheModel(): void
    {
        $hidden = self::texts(['mcp']);
        $revealed = self::texts(['mcp', '--reveal-links']);
        foreach (['instructions', 'list_coupons', 'get_coupon'] as $key) {
            self::assertIsString($hidden[$key]);
            self::assertIsString($revealed[$key]);
            self::assertStringContainsString('токен скрыт', $hidden[$key], $key);
            self::assertStringNotContainsString('токен скрыт', $revealed[$key], $key);
            self::assertStringContainsString('не публикуйте', $revealed[$key], $key);
        }
    }

    public function testDepthDefaultMatchesBehaviour(): void
    {
        $mcp = new McpTester();
        $mcp->send([McpTester::initialize(), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);
        $tools = (array) McpTester::result($mcp->responses[1])['tools'];
        self::assertIsArray($tools[0]);
        self::assertSame('get_categories', $tools[0]['name']);
        self::assertIsArray($tools[0]['inputSchema']);
        self::assertIsArray($tools[0]['inputSchema']['properties']);
        self::assertIsArray($tools[0]['inputSchema']['properties']['depth']);
        self::assertArrayNotHasKey('default', $tools[0]['inputSchema']['properties']['depth'], 'зависит от category_id');
    }

    public function testClosedStdoutEndsWithCode1(): void
    {
        $in = fopen('php://memory', 'r+b');
        $err = fopen('php://memory', 'r+b');
        self::assertIsResource($in);
        self::assertIsResource($err);
        fwrite($in, (string) json_encode(McpTester::initialize()) . "\n");
        rewind($in);
        $out = FailingStream::open();
        FailingStream::$failing = true;
        try {
            $application = new Application(static fn () => throw new \LogicException('без SDK'), new Console($in, $out, $err), [], FrozenClock::at('2026-10-07T10:00:00Z'));
            self::assertSame(1, $application->run(['mcp', '--no-cache']));
        } finally {
            FailingStream::$failing = false;
        }
        rewind($err);
        self::assertStringContainsString('stdout', (string) stream_get_contents($err));
    }

    public function testModernClient(): void
    {
        $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass()];
        $mcp = new McpTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('categories/categories.json')));
        $mcp->send([
            ['jsonrpc' => '2.0', 'id' => 'd', 'method' => 'server/discover', 'params' => ['_meta' => $meta]],
            ['jsonrpc' => '2.0', 'id' => 'c', 'method' => 'tools/call', 'params' => ['name' => 'get_categories', 'arguments' => ['limit' => 2], '_meta' => $meta]],
        ]);

        $discover = McpTester::result($mcp->responses[0]);
        self::assertContains('2026-07-28', (array) $discover['supportedVersions']);
        $call = McpTester::result($mcp->responses[1]);
        self::assertSame('complete', $call['resultType']);
        self::assertCount(2, (array) McpTester::structured($call)['categories']);
    }

    public function testUsage(): void
    {
        foreach ([['mcp', '--format=json'], ['mcp', 'lishniy'], ['mcp', '--nosuch']] as $argv) {
            $cli = new CliTester();
            self::assertSame(2, $cli->run($argv, [], McpTester::LEGACY), implode(' ', $argv));
            self::assertSame('', $cli->stdout, 'stdout — только протокол');
        }

        $cli = new CliTester();
        self::assertSame(0, $cli->run(['help', 'mcp']));
        self::assertStringContainsString('--reveal-links', $cli->stdout);
        self::assertStringContainsString('docs/mcp.md', $cli->stdout);

        $cli = new CliTester();
        self::assertSame(0, $cli->run(['help']));
        self::assertStringContainsString('mcp', $cli->stdout);
    }

    public function testEofEndsServer(): void
    {
        $cli = new CliTester();
        self::assertSame(0, $cli->run(['mcp'], [], ''));
        self::assertSame('', $cli->stdout);
    }

    public function testCredentialsAreCheckedPerTool(): void
    {
        $mcp = new McpTester();
        $text = McpTester::failure($mcp->call('search_offers', ['query' => 'платье']));
        self::assertStringStartsWith('[access] ', $text);
        self::assertStringContainsString('GDESLON_API_TOKEN', $text);
        self::assertStringContainsString('https://gdeslon.ru/api_settings/xml', $text);
        self::assertSame([], $mcp->cli->transport->requests());

        $mcp = new McpTester();
        self::assertStringContainsString('GDESLON_API_KEY', McpTester::failure($mcp->call('list_orders', [], ['GDESLON_USER_ID' => '1234'])));

        $mcp = new McpTester();
        self::assertStringContainsString('Ключи не приняты', McpTester::failure($mcp->call('list_orders', [], ['GDESLON_USER_ID' => 'abc', 'GDESLON_API_KEY' => 'k-12345'])));

        $mcp = new McpTester();
        self::assertStringContainsString('печатные символы ASCII', McpTester::failure($mcp->call('search_offers', [], ['GDESLON_API_TOKEN' => 'ab cd1234'])));

        $mcp = new McpTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('categories/categories.json')));
        McpTester::structured($mcp->call('get_categories', [], ['GDESLON_USER_ID' => 'abc', 'GDESLON_API_KEY' => 'k-12345', 'GDESLON_API_TOKEN' => 'ab cd1234']));
    }

    public function testInternalErrorKeepsServerAndHidesSecrets(): void
    {
        $failing = new class () implements CategoryRepository {
            public function all(): CategoryTree
            {
                throw new \RuntimeException('boom secret-token-0123');
            }
        };
        $mcp = new McpTester(build: static fn () => new GdeSlon(new FakeHttpTransport(), $failing));
        $mcp->send([
            McpTester::initialize(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_categories', 'arguments' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'],
        ], self::TOKEN);

        self::assertStringStartsWith('[internal] Внутренняя ошибка (RuntimeException): boom ***', McpTester::failure(McpTester::result($mcp->responses[1])));
        self::assertSame(3, $mcp->responses[2]['id'], 'сервер работает дальше');
        self::assertStringNotContainsString('secret-token-0123', $mcp->cli->stdout . $mcp->cli->stderr);
    }

    public function testFileCacheBetweenCalls(): void
    {
        $dir = sys_get_temp_dir() . '/gdeslon-mcp-' . bin2hex(random_bytes(4));
        $call = ['name' => 'list_merchant_categories', 'arguments' => new \stdClass()];

        $mcp = new McpTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops-public.xml')));
        $mcp->send([
            McpTester::initialize(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => $call],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => $call],
        ], [], ['mcp', '--cache-dir=' . $dir]);

        self::assertInstanceOf(FileCacheStore::class, $mcp->cli->cache);
        self::assertCount(1, $mcp->cli->transport->requests(), 'второй вызов — из кэша');
        self::assertSame(1, $mcp->cli->factoryCalls, 'фасад один на процесс');

        $mcp = new McpTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops-public.xml'))->willReturn(200, Fixtures::read('merchants/shops-public.xml')));
        $mcp->send([
            McpTester::initialize(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => $call],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => $call],
        ], [], ['mcp', '--no-cache']);
        self::assertCount(2, $mcp->cli->transport->requests(), '--no-cache — каждый вызов идёт в API');
    }

    /**
     * @param list<string> $argv
     *
     * @return array<array-key, mixed>
     */
    private static function texts(array $argv): array
    {
        $mcp = new McpTester();
        $mcp->send([McpTester::initialize(), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']], [], $argv);
        $texts = ['instructions' => McpTester::result($mcp->responses[0])['instructions']];
        foreach ((array) McpTester::result($mcp->responses[1])['tools'] as $tool) {
            self::assertIsArray($tool);
            self::assertIsString($tool['name']);
            $texts[$tool['name']] = $tool['description'];
        }

        return $texts;
    }
}
