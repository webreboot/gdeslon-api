<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Tests\Support\LocalHttpServer;
use Webreboot\GdeSlon\Tests\Support\SilentServer;
use Webreboot\GdeSlon\Tests\Support\StallingServer;
use Webreboot\GdeSlon\Tests\Support\TraceSecrets;

final class CurlTransportTest extends TestCase
{
    use TraceSecrets;

    private static LocalHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testGetJson(): void
    {
        $response = (new CurlTransport())->send(HttpRequest::get(self::$server->url('/json')));

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"ok":true,"name":"Всё для шитья"}', $response->body());
        self::assertSame('application/json; charset=utf-8', $response->header('content-type'));
    }

    public function testSendsQueryHeadersUserAgentAndAcceptEncoding(): void
    {
        $request = HttpRequest::get(self::$server->url('/echo'), ['q' => 'платье красное', 'l' => 5], ['X-Test' => 'да']);

        $response = (new CurlTransport())->send($request);

        /** @var array{method: string, query: array<string, string>, headers: array<string, string>} $echo */
        $echo = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('GET', $echo['method']);
        self::assertSame(['q' => 'платье красное', 'l' => '5'], $echo['query']);
        self::assertSame('да', $echo['headers']['x-test']);
        self::assertStringStartsWith('webreboot-gdeslon-api/', $echo['headers']['user-agent']);
        self::assertArrayHasKey('accept-encoding', $echo['headers']);
    }

    public function testCustomUserAgent(): void
    {
        $response = (new CurlTransport(userAgent: 'my-app/1.0'))->send(HttpRequest::get(self::$server->url('/echo')));

        /** @var array{headers: array<string, string>} $echo */
        $echo = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('my-app/1.0', $echo['headers']['user-agent']);
    }

    public function testErrorStatusesAreResponsesNotExceptions(): void
    {
        $transport = new CurlTransport();

        self::assertSame(404, $transport->send(HttpRequest::get(self::$server->url('/status/404')))->statusCode());
        self::assertSame(500, $transport->send(HttpRequest::get(self::$server->url('/status/500')))->statusCode());
    }

    public function testRepeatedHeadersAreKept(): void
    {
        $response = (new CurlTransport())->send(HttpRequest::get(self::$server->url('/dup-headers')));

        self::assertSame(['first', 'second'], $response->headerValues('x-dup'));
    }

    public function testGzipIsDecoded(): void
    {
        $response = (new CurlTransport())->send(HttpRequest::get(self::$server->url('/gzip')));

        self::assertSame('{"gzip":true}', $response->body());
    }

    public function testRedirectIsNotFollowed(): void
    {
        $response = (new CurlTransport())->send(HttpRequest::get(self::$server->url('/redirect')));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/json', $response->header('location'));
    }

    public function testTimeout(): void
    {
        $request = HttpRequest::get(self::$server->url('/sleep/1500'), ['_gs_at' => 'secret-token']);
        $started = microtime(true);

        try {
            (new CurlTransport(timeout: 0.3))->send($request);
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertNotInstanceOf(ConnectTimeoutException::class, $e);
            self::assertLessThan(1.4, microtime(true) - $started);
            self::assertSame(28, $e->curlErrorCode());
            self::assertStringNotContainsString('secret-token', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->url());
        }

        usleep(1_300_000);
    }

    public function testRequestTimeoutShortensTransportTimeout(): void
    {
        $started = microtime(true);

        try {
            (new CurlTransport(timeout: 30.0))->send(HttpRequest::get(self::$server->url('/sleep/1500'))->withTimeout(0.3));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertNotInstanceOf(ConnectTimeoutException::class, $e);
            self::assertLessThan(1.4, microtime(true) - $started);
        }

        usleep(1_300_000);
    }

    public function testTlsHandshakeHangIsConnectTimeout(): void
    {
        $server = SilentServer::start();
        $request = HttpRequest::get($server->url('https'), ['_gs_at' => 'secret-token', 'q' => 'платье']);
        $started = microtime(true);

        try {
            $e = self::catchTimeout(new CurlTransport(timeout: 5.0, connectTimeout: 0.3), $request);
        } finally {
            $server->stop();
        }

        self::assertInstanceOf(ConnectTimeoutException::class, $e);
        self::assertLessThan(2.0, microtime(true) - $started);
        self::assertSame(28, $e->curlErrorCode());
        self::assertSame('GET', $e->method());
        self::assertSame($request->maskedUri(), $e->url());
        self::assertStringStartsWith('GET ' . $request->maskedUri() . ': превышено время установки соединения (cURL 28: ', $e->getMessage());
        self::assertStringNotContainsString('secret-token', $e->getMessage());
        self::assertStringNotContainsString('secret-token', $e->url());
    }

    public function testTotalTimeoutDuringConnectIsConnectTimeout(): void
    {
        $server = SilentServer::start();
        $started = microtime(true);

        try {
            $e = self::catchTimeout(new CurlTransport(timeout: 0.3, connectTimeout: 5.0), HttpRequest::get($server->url('https')));
        } finally {
            $server->stop();
        }

        self::assertInstanceOf(ConnectTimeoutException::class, $e);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testFullAcceptQueueIsConnectTimeout(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Переполненная очередь accept отбрасывает SYN только в Linux');
        }
        $server = SilentServer::withFullAcceptQueue();
        $started = microtime(true);

        try {
            $e = self::catchTimeout(new CurlTransport(timeout: 5.0, connectTimeout: 0.3), HttpRequest::get($server->url()));
        } finally {
            $server->stop();
        }

        self::assertInstanceOf(ConnectTimeoutException::class, $e);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testProxyConnectHangIsConnectTimeout(): void
    {
        self::requireLibcurlCountingConnectApart();
        $proxy = SilentServer::start();
        $started = microtime(true);

        try {
            $e = self::withHttpsProxy(
                $proxy->proxy(),
                static fn (): TimeoutException => self::catchTimeout(new CurlTransport(timeout: 5.0, connectTimeout: 0.3), HttpRequest::get('https://example.invalid/', ['_gs_at' => 'secret-token'])),
            );
        } finally {
            $proxy->stop();
        }

        self::assertInstanceOf(ConnectTimeoutException::class, $e);
        self::assertLessThan(2.0, microtime(true) - $started);
        self::assertSame('https://example.invalid/?_gs_at=***', $e->url());
    }

    public function testProxyEstablishedThenTlsHangIsConnectTimeout(): void
    {
        self::requireLibcurlCountingConnectApart();
        $proxy = StallingServer::proxyEstablishingTunnel();
        $started = microtime(true);

        try {
            $e = self::withHttpsProxy(
                $proxy->proxy(),
                static fn (): TimeoutException => self::catchTimeout(new CurlTransport(timeout: 5.0, connectTimeout: 0.3), HttpRequest::get('https://example.invalid/')),
            );
        } finally {
            $proxy->stop();
        }

        self::assertInstanceOf(ConnectTimeoutException::class, $e);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testSilentServerAfterAcceptIsReadTimeout(): void
    {
        $server = SilentServer::start();

        try {
            $e = self::catchTimeout(new CurlTransport(timeout: 0.3, connectTimeout: 5.0), HttpRequest::get($server->url()));
        } finally {
            $server->stop();
        }

        self::assertSame(TimeoutException::class, $e::class);
        self::assertStringContainsString(': превышено время ожидания (cURL 28: ', $e->getMessage());
    }

    public function testPartialBodyThenStallIsReadTimeout(): void
    {
        $server = StallingServer::partialBody();

        try {
            $e = self::catchTimeout(new CurlTransport(timeout: 0.3, connectTimeout: 5.0), HttpRequest::get($server->url('/api/search.xml')));
        } finally {
            $server->stop();
        }

        self::assertSame(TimeoutException::class, $e::class);
        self::assertStringContainsString('16011 out of 100000 bytes', $e->getMessage());
    }

    public function testConnectTimeoutDoesNotLeakSecretsThroughTrace(): void
    {
        self::requireSensitiveParameter();
        $token = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';
        $basic = base64_encode('1234:test-api-key-0123');
        $server = SilentServer::start();
        $request = HttpRequest::get($server->url('https'), ['_gs_at' => $token], ['Authorization' => 'Basic ' . $basic]);

        try {
            $e = self::catchWithTraceArgs(static fn () => (new CurlTransport(timeout: 5.0, connectTimeout: 0.3))->send($request), ConnectTimeoutException::class);
        } finally {
            $server->stop();
        }

        self::assertTraceHidesSecrets($e, $token, $basic);
    }

    public function testConnectionRefused(): void
    {
        $request = HttpRequest::get('http://127.0.0.1:' . LocalHttpServer::freePort() . '/');

        try {
            (new CurlTransport())->send($request);
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame(7, $e->curlErrorCode());
        }
    }

    public function testConnectionRefusedDoesNotLeakSecretsThroughTrace(): void
    {
        self::requireSensitiveParameter();
        $token = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';
        $basic = base64_encode('1234:test-api-key-0123');
        $request = HttpRequest::get('http://127.0.0.1:' . LocalHttpServer::freePort() . '/', ['_gs_at' => $token], ['Authorization' => 'Basic ' . $basic]);

        $e = self::catchWithTraceArgs(static fn () => (new CurlTransport())->send($request), TransportException::class);

        self::assertSame(7, $e->curlErrorCode());
        self::assertTraceHidesSecrets($e, $token, $basic);
    }

    public function testTruncatedBody(): void
    {
        try {
            (new CurlTransport())->send(HttpRequest::get(self::$server->url('/truncated')));
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame(18, $e->curlErrorCode());
        }
    }

    public function testFromConfigAppliesUserAgent(): void
    {
        $transport = CurlTransport::fromConfig(new Config(userAgent: 'my-app/1.0'));

        /** @var array{headers: array<string, string>} $echo */
        $echo = json_decode($transport->send(HttpRequest::get(self::$server->url('/echo')))->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('my-app/1.0', $echo['headers']['user-agent']);
    }

    public function testFromConfigAppliesTimeout(): void
    {
        $started = microtime(true);

        try {
            CurlTransport::fromConfig(new Config(timeout: 0.3, connectTimeout: 5.0))
                ->send(HttpRequest::get(self::$server->url('/sleep/1500')));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertNotInstanceOf(ConnectTimeoutException::class, $e);
            self::assertLessThan(1.4, microtime(true) - $started);
        }

        usleep(1_300_000);
    }

    public function testFromDefaultConfigUsesPackageUserAgent(): void
    {
        $transport = CurlTransport::fromConfig(new Config());

        /** @var array{headers: array<string, string>} $echo */
        $echo = json_decode($transport->send(HttpRequest::get(self::$server->url('/echo')))->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(CurlTransport::defaultUserAgent(), $echo['headers']['user-agent']);
    }

    public function testRejectsNonPositiveTimeouts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CurlTransport(timeout: 0.0);
    }

    public function testPostSendsBodyAndHeaders(): void
    {
        $body = '{"created_at":{"date":"2026-10-07","period":30},"sub_id":"тест"}';
        $request = HttpRequest::post(self::$server->url('/echo'), $body, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode('1234:test-key'),
        ]);

        $response = (new CurlTransport())->send($request);

        /** @var array{method: string, body: string, headers: array<string, string>} $echo */
        $echo = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('POST', $echo['method']);
        self::assertSame($body, $echo['body']);
        self::assertSame('application/json', $echo['headers']['content-type']);
        self::assertSame('Basic ' . base64_encode('1234:test-key'), $echo['headers']['authorization']);
    }

    private static function catchTimeout(CurlTransport $transport, HttpRequest $request): TimeoutException
    {
        try {
            $transport->send($request);
        } catch (TimeoutException $e) {
            return $e;
        }
        self::fail('Ожидался TimeoutException');
    }

    /**
     * @template T
     * @param callable(): T $action
     *
     * @return T
     */
    private static function withHttpsProxy(string $proxy, callable $action): mixed
    {
        $environment = ['https_proxy' => $proxy, 'no_proxy' => '', 'NO_PROXY' => ''];
        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        try {
            return $action();
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    private static function requireLibcurlCountingConnectApart(): void
    {
        $version = curl_version();
        if (!is_array($version) || $version['version_number'] < 0x080E00) {
            self::markTestSkipped('До libcurl 8.14 байты CONNECT прокси попадают в REQUEST_SIZE: таймаут туннеля остаётся TimeoutException');
        }
    }
}
