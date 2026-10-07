<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Interface\Cli\Console;
use Webreboot\GdeSlon\Interface\Cli\Environment;
use Webreboot\GdeSlon\Interface\Mcp\Protocol;
use Webreboot\GdeSlon\Interface\Mcp\ProtocolVersion;
use Webreboot\GdeSlon\Interface\Mcp\StdioChannel;
use Webreboot\GdeSlon\Interface\Mcp\ToolCatalog;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Tests\Support\EchoTool;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;

final class ProtocolTest extends TestCase
{
    private const MODERN_META = '"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}';

    /** @var resource */
    private $log;

    public function testParseError(): void
    {
        self::assertSame('{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}', $this->protocol()->handle('{'));
        self::assertSame(-32600, self::error($this->protocol()->handle('[]'))['code']);
        self::assertSame(-32600, self::error($this->protocol()->handle(StdioChannel::TOO_LONG))['code']);
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequest(string $message, int|string|null $id): void
    {
        $response = self::decode($this->protocol()->handle($message));

        self::assertSame($id, $response['id']);
        self::assertSame(-32600, self::error($response)['code']);
    }

    /**
     * @return iterable<string, array{string, int|string|null}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'скаляр' => ['5', null];
        yield 'без jsonrpc' => ['{"id":1,"method":"ping"}', 1];
        yield 'jsonrpc 1.0' => ['{"jsonrpc":"1.0","id":"abc","method":"ping"}', 'abc'];
        yield 'method не строка' => ['{"jsonrpc":"2.0","id":7,"method":5}', 7];
        yield 'id null' => ['{"jsonrpc":"2.0","id":null,"method":"ping"}', null];
        yield 'id bool' => ['{"jsonrpc":"2.0","id":true,"method":"ping"}', null];
        yield 'id дробный' => ['{"jsonrpc":"2.0","id":1.5,"method":"ping"}', null];
        yield 'id объект' => ['{"jsonrpc":"2.0","id":{},"method":"ping"}', null];
        yield 'params массив' => ['{"jsonrpc":"2.0","id":2,"method":"ping","params":[]}', 2];
        yield 'params строка' => ['{"jsonrpc":"2.0","id":2,"method":"ping","params":"x"}', 2];
    }

    public function testNotificationsGetNoResponse(): void
    {
        $protocol = $this->protocol();

        self::assertNull($protocol->handle('{"jsonrpc":"2.0","method":"nosuch"}'));
        self::assertNull($protocol->handle('{"jsonrpc":"2.0","method":"notifications/x","params":{}}'));
        self::assertNull($protocol->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
        self::assertNull($protocol->handle('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":2}}'));
        self::assertSame('{"jsonrpc":"2.0","id":3,"result":{}}', $protocol->handle('{"jsonrpc":"2.0","id":3,"method":"ping"}'));
    }

    public function testInvalidMessagesWithoutIdGetError(): void
    {
        foreach (['{"jsonrpc":"2.0","method":1}', '{"jsonrpc":"2.0","method":"tools/call","params":"x"}', '{"method":"ping"}', '{"foo":"boo"}'] as $message) {
            $response = self::decode($this->protocol()->handle($message));
            self::assertNull($response['id'], $message);
            self::assertSame(-32600, self::error($response)['code'], $message);
        }

        $batch = self::decodeList($this->protocol()->handle('[{"foo":"boo"}]'));
        self::assertCount(1, $batch);
        self::assertSame(-32600, self::error($batch[0])['code']);
    }

    public function testProtocolErrorsAreMasked(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-11-25');

        foreach ([
            '{"jsonrpc":"2.0","id":1,"method":"secret-token-0123"}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"secret-token-0123"}}',
            '{"jsonrpc":"2.0","id":3,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"secret-token-0123","io.modelcontextprotocol/clientCapabilities":{}}}}',
        ] as $message) {
            $line = (string) $protocol->handle($message);
            self::assertStringNotContainsString('secret-token-0123', $line, $message);
            self::assertStringContainsString('***', $line, $message);
        }
        self::assertSame('{"jsonrpc":"2.0","id":"secret-token-0123","result":{}}', $protocol->handle('{"jsonrpc":"2.0","id":"secret-token-0123","method":"ping"}'), 'id возвращается как есть');
    }

    public function testRevealedResultsKeepTokenButErrorsDoNot(): void
    {
        $protocol = $this->protocol(reveal: true, exposesToken: true);
        self::initialize($protocol, '2025-11-25');

        $line = (string) $protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"value":"token secret-token-0123"}}}');
        self::assertStringContainsString('token secret-token-0123', $line, '--reveal-links: инструмент со ссылками отдаёт токен');

        $line = (string) $protocol->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"value":"failed"}}}');
        self::assertStringContainsString('[failed]', $line);
        self::assertStringNotContainsString('secret-token-0123', $line, 'в ошибках — всегда маска');

        $plain = $this->protocol(reveal: true, exposesToken: false);
        self::initialize($plain, '2025-11-25');
        $line = (string) $plain->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"value":"token secret-token-0123"}}}');
        self::assertStringNotContainsString('secret-token-0123', $line, 'остальные инструменты маскируются и с --reveal-links');
    }

    public function testInstructionsFollowRevealLinks(): void
    {
        $hidden = self::ok(self::decode($this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}')));
        self::assertIsString($hidden['instructions']);
        self::assertStringContainsString('токен скрыт', $hidden['instructions']);

        $revealed = self::ok(self::decode($this->protocol(reveal: true)->handle('{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{' . self::MODERN_META . '}}')));
        self::assertIsString($revealed['instructions']);
        self::assertStringNotContainsString('токен скрыт', $revealed['instructions']);
        self::assertStringContainsString('не публикуйте', $revealed['instructions']);
    }

    public function testMethodNotFound(): void
    {
        foreach (['nosuch', 'subscriptions/listen', 'resources/list'] as $method) {
            self::assertSame(-32601, self::error($this->protocol()->handle(sprintf('{"jsonrpc":"2.0","id":1,"method":"%s"}', $method)))['code'], $method);
        }
    }

    public function testBatch(): void
    {
        $protocol = $this->protocol();

        $response = self::decodeList($protocol->handle('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/x"},{"jsonrpc":"2.0","id":2,"method":"nosuch"},5]'));
        self::assertCount(3, $response);
        self::assertSame(1, $response[0]['id']);
        self::assertSame(-32601, self::error($response[1])['code']);
        self::assertSame(-32600, self::error($response[2])['code']);

        self::assertNull($protocol->handle('[{"jsonrpc":"2.0","method":"notifications/initialized"}]'));
    }

    public function testInternalErrorHidesDetails(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-11-25');

        $response = $protocol->handle('{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"echo","arguments":{"value":"inf"}}}');

        self::assertSame('{"jsonrpc":"2.0","id":5,"error":{"code":-32603,"message":"Internal error"}}', $response);
        rewind($this->log);
        self::assertStringContainsString('Inf and NaN', (string) stream_get_contents($this->log), 'подробности — в stderr');
    }

    #[DataProvider('legacyVersions')]
    public function testInitialize(string $requested, string $negotiated): void
    {
        $response = self::decode($this->protocol()->handle(sprintf(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"%s","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}',
            $requested,
        )));
        $result = self::ok($response);

        self::assertSame($negotiated, $result['protocolVersion']);
        self::assertSame(['tools' => []], $result['capabilities']);
        self::assertSame(['name' => 'gdeslon-api', 'title' => 'Где Слон? (неофициальный)', 'version' => GdeSlon::VERSION], $result['serverInfo']);
        self::assertIsString($result['instructions']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyVersions(): iterable
    {
        foreach (ProtocolVersion::LEGACY as $version) {
            yield $version => [$version, $version];
        }
        yield 'неизвестная' => ['1900-01-01', '2025-11-25'];
        yield 'modern в initialize' => ['2026-07-28', '2025-11-25'];
    }

    public function testInitializeCapabilitiesAreObjects(): void
    {
        $line = (string) $this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{}}}');

        self::assertStringContainsString('"capabilities":{"tools":{}}', $line);
    }

    public function testInitializeWithoutVersion(): void
    {
        self::assertSame(-32602, self::error($this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'))['code']);
        self::assertSame(-32602, self::error($this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"initialize"}'))['code']);
    }

    public function testToolsNeedSessionOrMeta(): void
    {
        $protocol = $this->protocol();

        $error = self::error($protocol->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        self::assertSame(-32602, $error['code']);
        self::assertIsString($error['message']);
        self::assertStringContainsString('initialize', $error['message']);

        self::initialize($protocol, '2025-06-18');
        $result = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}')));
        self::assertSame(['tools'], array_keys($result), 'legacy — без resultType и ttlMs');
        self::assertIsArray($result['tools']);
        self::assertCount(1, $result['tools']);
    }

    public function testDiscover(): void
    {
        $result = self::ok(self::decode($this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{' . self::MODERN_META . '}}')));

        self::assertSame('complete', $result['resultType']);
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'], $result['supportedVersions']);
        self::assertSame(['tools' => []], $result['capabilities']);
        self::assertIsInt($result['ttlMs']);
        self::assertContains($result['cacheScope'], ['public', 'private']);
        self::assertIsString($result['instructions']);
        self::assertIsArray($result['_meta']);
        self::assertIsArray($result['_meta']['io.modelcontextprotocol/serverInfo']);
        self::assertSame('gdeslon-api', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
    }

    public function testModernToolsList(): void
    {
        $result = self::ok(self::decode($this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{' . self::MODERN_META . '}}')));

        self::assertSame('complete', $result['resultType']);
        self::assertIsInt($result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
        self::assertIsArray($result['tools']);
        self::assertArrayHasKey('_meta', $result);
    }

    public function testModernVersionErrors(): void
    {
        $error = self::error($this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2027-01-01","io.modelcontextprotocol/clientCapabilities":{}}}}'));
        self::assertSame(-32022, $error['code']);
        self::assertSame(['supported' => ProtocolVersion::MODERN, 'requested' => '2027-01-01'], $error['data'], 'только версии, которые принимаются в _meta');

        $error = self::error($this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2025-11-25","io.modelcontextprotocol/clientCapabilities":{}}}}'));
        self::assertSame(-32022, $error['code']);
        self::assertIsArray($error['data']);
        self::assertNotContains('2025-11-25', (array) $error['data']['supported'], 'клиент не зациклится на той же версии');

        foreach (['', ',"io.modelcontextprotocol/clientCapabilities":[]'] as $capabilities) {
            $error = self::error($this->protocol()->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28"' . $capabilities . '}}}'));
            self::assertSame(-32602, $error['code']);
        }
    }

    public function testErasCanAlternate(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-06-18');

        $modern = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{' . self::MODERN_META . '}}')));
        self::assertArrayHasKey('resultType', $modern);

        $legacy = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":3,"method":"tools/list"}')));
        self::assertArrayNotHasKey('resultType', $legacy);
    }

    public function testToolCall(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-06-18');

        $result = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"value":"привет"}}}')));
        self::assertSame([['type' => 'text', 'text' => '{"value":"привет"}']], $result['content']);
        self::assertSame(['value' => 'привет'], $result['structuredContent']);
        self::assertArrayNotHasKey('isError', $result);

        $result = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo"}}')));
        self::assertSame(['value' => null], $result['structuredContent'], 'без arguments — как пустой объект');

        $modern = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"echo","arguments":{},' . self::MODERN_META . '}}')));
        self::assertSame('complete', $modern['resultType']);
        self::assertArrayHasKey('structuredContent', $modern);
    }

    public function testOldVersionsGetTextOnly(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-03-26');

        $result = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"value":"x"}}}')));

        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertSame([['type' => 'text', 'text' => '{"value":"x"}']], $result['content']);
    }

    public function testToolCallErrors(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-11-25');

        foreach (['{"name":"nosuch"}', '{"name":"echo","arguments":[]}', '{"name":5}', '{}'] as $params) {
            self::assertSame(-32602, self::error($protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":' . $params . '}'))['code'], $params);
        }

        $result = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"value":5,"x":1}}}')));
        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertIsArray($result['content']);
        self::assertIsArray($result['content'][0]);
        self::assertIsString($result['content'][0]['text']);
        self::assertStringStartsWith('[invalid_arguments] ', $result['content'][0]['text']);
        self::assertStringContainsString('неизвестный аргумент x', $result['content'][0]['text']);
    }

    public function testToolErrorsAreTaggedAndMasked(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-11-25');

        foreach ([
            'boom' => '[internal] Внутренняя ошибка (RuntimeException): boom ***',
            'failed' => '[failed] Ошибка: HTTP 500 ***',
            'access' => '[access] Ошибка доступа: HTTP 401',
            'not_found' => '[not_found] Записи 5 нет',
        ] as $value => $expected) {
            $result = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"value":"' . $value . '"}}}')));
            self::assertTrue($result['isError'], $value);
            self::assertIsArray($result['content']);
            self::assertIsArray($result['content'][0]);
            self::assertIsString($result['content'][0]['text']);
            self::assertStringStartsWith($expected, $result['content'][0]['text'], (string) $value);
            self::assertStringNotContainsString('secret-token-0123', $result['content'][0]['text']);
        }
    }

    public function testStructuredValuesAreMasked(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-11-25');

        $line = (string) $protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"value":"token secret-token-0123"}}}');

        self::assertStringNotContainsString('secret-token-0123', $line);
        self::assertStringContainsString('token ***', $line);
    }

    public function testTooLargeResult(): void
    {
        $protocol = $this->protocol();
        self::initialize($protocol, '2025-11-25');

        $result = self::ok(self::decode($protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"value":"huge"}}}')));

        self::assertTrue($result['isError']);
        self::assertIsArray($result['content']);
        self::assertIsArray($result['content'][0]);
        self::assertIsString($result['content'][0]['text']);
        self::assertStringStartsWith('[too_large] ', $result['content'][0]['text']);
    }

    private function protocol(bool $reveal = false, bool $exposesToken = false): Protocol
    {
        $stream = static function () {
            $stream = fopen('php://memory', 'r+b');
            self::assertIsResource($stream);

            return $stream;
        };
        $this->log = $stream();
        $console = new Console($stream(), $stream(), $this->log, ['secret-token-0123']);
        $context = new ToolContext(static fn () => throw new \LogicException('без SDK'), FrozenClock::at('2026-10-07T10:00:00Z'), new Environment([]), $console, $reveal);

        return new Protocol(new ToolCatalog([new EchoTool($exposesToken)]), $context);
    }

    private static function initialize(Protocol $protocol, string $version): void
    {
        self::ok(self::decode($protocol->handle(sprintf('{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"%s","capabilities":{}}}', $version))));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $line): array
    {
        self::assertNotNull($line);
        self::assertStringNotContainsString("\n", $line);
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('2.0', $data['jsonrpc'] ?? null);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeList(?string $line): array
    {
        self::assertNotNull($line);
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue(array_is_list($data));

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /**
     * @param array<string, mixed>|string|null $response
     *
     * @return array<string, mixed>
     */
    private static function error(array|string|null $response): array
    {
        $response = is_array($response) ? $response : self::decode($response);
        self::assertArrayNotHasKey('result', $response);
        self::assertIsArray($response['error']);

        /** @var array<string, mixed> */
        return $response['error'];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private static function ok(array $response): array
    {
        self::assertArrayNotHasKey('error', $response, (string) json_encode($response['error'] ?? null));
        self::assertIsArray($response['result']);

        /** @var array<string, mixed> */
        return $response['result'];
    }
}
