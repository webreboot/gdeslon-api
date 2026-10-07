<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Tests\Support\CountingTransport;
use Webreboot\GdeSlon\Tests\Support\LocalHttpServer;

final class RangeTransportCurlTest extends TestCase
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
