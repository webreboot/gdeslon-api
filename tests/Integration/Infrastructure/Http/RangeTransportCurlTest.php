<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpProductCatalog;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;
use Webreboot\GdeSlon\Tests\Support\CountingTransport;
use Webreboot\GdeSlon\Tests\Support\LocalHttpServer;
use Webreboot\GdeSlon\Tests\Support\TraceSecrets;

final class RangeTransportCurlTest extends TestCase
{
    use TraceSecrets;

    public function testFullStackTransportErrorDoesNotLeakToken(): void
    {
        self::requireSensitiveParameter();
        $token = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';
        $transport = TransportFactory::fromConfig(new Config(apiToken: $token), ['127.0.0.1']);
        $catalog = new HttpProductCatalog(new ApiClient($transport), $token, 'http://127.0.0.1:' . LocalHttpServer::freePort() . '/api/search.xml');

        $e = self::catchWithTraceArgs(static fn () => $catalog->search(new SearchCriteria(query: 'платье')), TransportException::class);

        $functions = array_map(static fn (array $frame): string => ($frame['class'] ?? '') . '::' . $frame['function'], $e->getTrace());
        self::assertContains(RangeTransport::class . '::send', $functions);
        self::assertContains(CurlTransport::class . '::send', $functions);
        self::assertTraceHidesSecrets($e, $token);
    }
    private static LocalHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testAssemblesFileFromParts(): void
    {
        $spy = new CountingTransport(new CurlTransport());
        $transport = new RangeTransport($spy, chunkSize: 4096, hosts: ['127.0.0.1']);

        $response = $transport->send(HttpRequest::get(self::$server->url('/ranged')));

        $expected = substr(str_repeat('{"категория":"Всё для шитья"}', 1500), 0, 40000);
        self::assertSame(200, $response->statusCode());
        self::assertSame($expected, $response->body());
        self::assertSame(['40000'], $response->headerValues('content-length'));
        self::assertSame(10, $spy->count());
    }

    public function testServerIgnoringRange(): void
    {
        $spy = new CountingTransport(new CurlTransport());
        $transport = new RangeTransport($spy, chunkSize: 4096, hosts: ['127.0.0.1']);

        $response = $transport->send(HttpRequest::get(self::$server->url('/ranged'), ['ignore' => 1]));

        self::assertSame(200, $response->statusCode());
        self::assertSame(40000, strlen($response->body()));
        self::assertSame(1, $spy->count());
    }
}
