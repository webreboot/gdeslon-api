<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Tests\Support\LocalHttpServer;

final class CurlTransportTest extends TestCase
{
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
            self::assertLessThan(1.4, microtime(true) - $started);
            self::assertSame(28, $e->curlErrorCode());
            self::assertStringNotContainsString('secret-token', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->url());
        }

        usleep(1_300_000); // php -S однопоточный: дождаться, пока он доспит, чтобы не мешать следующим тестам
    }

    public function testRequestTimeoutShortensTransportTimeout(): void
    {
        $started = microtime(true);

        try {
            (new CurlTransport(timeout: 30.0))->send(HttpRequest::get(self::$server->url('/sleep/1500'))->withTimeout(0.3));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertLessThan(1.4, microtime(true) - $started);
        }

        usleep(1_300_000);
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
}
