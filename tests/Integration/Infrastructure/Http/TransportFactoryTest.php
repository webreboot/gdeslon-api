<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\FakeRangeServer;
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
        $transport = TransportFactory::fromConfig(new Config(rangeChunkSize: 8192), rangeHosts: ['127.0.0.1']);

        /** @var array{headers: array<string, string>} $echo */
        $echo = json_decode($transport->send(HttpRequest::get(self::$server->url('/echo')))->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('bytes=0-8191', $echo['headers']['range']);
    }

    public function testInnerIsWrappedWithRangeFromConfig(): void
    {
        foreach ([16384 => 'bytes=0-16383', 8192 => 'bytes=0-8191'] as $chunkSize => $range) {
            $inner = (new FakeHttpTransport())->willReturn(200, '{}');

            $response = TransportFactory::fromConfig(new Config(rangeChunkSize: $chunkSize), $inner)
                ->send(HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json'));

            self::assertSame($range, $inner->lastRequest()->header('Range'));
            self::assertSame('close', $inner->lastRequest()->header('Connection'));
            self::assertSame(200, $response->statusCode());
            self::assertSame('{}', $response->body());
        }
    }

    public function testInnerReturnedAsIsWhenRangeDisabled(): void
    {
        $inner = new FakeHttpTransport();

        self::assertSame($inner, TransportFactory::fromConfig(new Config(rangeChunkSize: null), $inner));
        self::assertSame($inner, TransportFactory::fromConfig(new Config(rangeChunkSize: null), inner: $inner, rangeHosts: ['example.test']));
    }

    public function testInnerPartsAreAssembled(): void
    {
        $body = str_repeat('{"категория":"Всё для шитья"}', 800);
        $server = new FakeRangeServer($body);

        $response = TransportFactory::fromConfig(new Config(), $server)
            ->send(HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json'));

        self::assertSame(200, $response->statusCode());
        self::assertSame($body, $response->body());
        self::assertSame((string) strlen($body), $response->header('Content-Length'));
        self::assertNull($response->header('Content-Range'));
        self::assertSame(['bytes=0-16383', 'bytes=16384-32767', 'bytes=32768-49151'], $server->ranges());
    }

    public function testInnerSeesOtherHostsAndPostUnchanged(): void
    {
        $inner = (new FakeHttpTransport())->willReturn(200, '<shops/>')->willReturn(201, '{}');
        $transport = TransportFactory::fromConfig(new Config(), $inner);
        $get = HttpRequest::get('https://www.gdeslon.ru/api/users/shops.xml', ['api_token' => 't']);
        $post = HttpRequest::post('https://api.gdeslon.ru/gdeslon-categories.json', '{}');

        self::assertSame('<shops/>', $transport->send($get)->body());
        self::assertSame(201, $transport->send($post)->statusCode());
        self::assertSame([$get, $post], $inner->requests());
    }

    public function testRangeHostsOverrideDefaults(): void
    {
        $inner = (new FakeHttpTransport())->willReturn(200, '{}')->willReturn(200, '{}');
        $transport = TransportFactory::fromConfig(new Config(), $inner, rangeHosts: ['example.test']);

        $transport->send(HttpRequest::get('https://example.test/feed.json'));
        $transport->send(HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json'));

        self::assertSame(['bytes=0-16383', null], array_map(static fn (HttpRequest $request): ?string => $request->header('Range'), $inner->requests()));
    }

    public function testEmptyRangeHostsRejectedOnlyWhenRangeEnabled(): void
    {
        $inner = new FakeHttpTransport();

        self::assertSame($inner, TransportFactory::fromConfig(new Config(rangeChunkSize: null), $inner, rangeHosts: []));

        $this->expectException(InvalidArgumentException::class);
        TransportFactory::fromConfig(new Config(), $inner, rangeHosts: []);
    }

    public function testInnerFailurePropagates(): void
    {
        $failure = new TransportException('соединение сброшено', 'GET', 'https://api.gdeslon.ru/gdeslon-categories.json', 7);
        $inner = (new FakeHttpTransport())->willThrow($failure);

        try {
            TransportFactory::fromConfig(new Config(), $inner)->send(HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json'));
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertSame($failure, $e);
        }

        $body = str_repeat('x', 40000);
        $server = (new FakeRangeServer($body))->failPart(16384, 28);
        $response = TransportFactory::fromConfig(new Config(), $server)->send(HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json'));
        self::assertSame($body, $response->body());
        self::assertSame(['bytes=0-16383', 'bytes=16384-32767', 'bytes=16384-24575', 'bytes=24576-32767', 'bytes=32768-40959'], $server->ranges());

        $server = (new FakeRangeServer($body))->failPart(16384, 28, 3);
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('загрузка частями прервана на байте 16384 из 40000 после 3 попыток');
        TransportFactory::fromConfig(new Config(), $server)->send(HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json'));
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
