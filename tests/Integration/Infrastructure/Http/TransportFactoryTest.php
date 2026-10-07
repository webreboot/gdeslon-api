<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;
use Webreboot\GdeSlon\Tests\Support\LocalHttpServer;

final class TransportFactoryTest extends TestCase
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

    public function testRangeIsOnByDefault(): void
    {
        self::assertInstanceOf(RangeTransport::class, TransportFactory::fromConfig(new Config()));
        self::assertInstanceOf(CurlTransport::class, TransportFactory::fromConfig(new Config(rangeChunkSize: null)));
    }

    public function testChunkSizeFromConfig(): void
    {
        // /echo не поддерживает Range и возвращает заголовки запроса как есть
        $transport = TransportFactory::fromConfig(new Config(rangeChunkSize: 8192), ['127.0.0.1']);

        /** @var array{headers: array<string, string>} $echo */
        $echo = json_decode($transport->send(HttpRequest::get(self::$server->url('/echo')))->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('bytes=0-8191', $echo['headers']['range']);
    }

    #[DataProvider('rangeSettings')]
    public function testUserAgentFromConfig(?int $rangeChunkSize): void
    {
        $transport = TransportFactory::fromConfig(new Config(userAgent: 'my-app/1.0', rangeChunkSize: $rangeChunkSize));

        /** @var array{headers: array<string, string>} $echo */
        $echo = json_decode($transport->send(HttpRequest::get(self::$server->url('/echo')))->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('my-app/1.0', $echo['headers']['user-agent']);
    }

    #[DataProvider('rangeSettings')]
    public function testTimeoutFromConfig(?int $rangeChunkSize): void
    {
        $transport = TransportFactory::fromConfig(new Config(timeout: 0.3, connectTimeout: 5.0, rangeChunkSize: $rangeChunkSize));
        $started = microtime(true);

        try {
            $transport->send(HttpRequest::get(self::$server->url('/sleep/1500')));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException) {
            self::assertLessThan(1.4, microtime(true) - $started);
        }

        usleep(1_300_000);
    }

    /**
     * @return iterable<string, array{?int}>
     */
    public static function rangeSettings(): iterable
    {
        yield 'с загрузкой частями' => [16384];
        yield 'без загрузки частями' => [null];
    }
}
