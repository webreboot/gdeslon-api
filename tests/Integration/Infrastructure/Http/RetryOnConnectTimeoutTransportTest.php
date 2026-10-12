<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\RetryOnConnectTimeoutTransport;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\TraceSecrets;

final class RetryOnConnectTimeoutTransportTest extends TestCase
{
    use TraceSecrets;

    private const URL = 'https://api.gdeslon.ru/api/search.xml';

    private const TOKEN = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';

    public function testResponsePassesThrough(): void
    {
        $inner = (new FakeHttpTransport())->willReturn(200, '<yml_catalog/>');

        $response = (new RetryOnConnectTimeoutTransport($inner))->send(self::search());

        self::assertSame('<yml_catalog/>', $response->body());
        self::assertCount(1, $inner->requests());
    }

    public function testGetIsRetriedAfterConnectTimeout(): void
    {
        $inner = (new FakeHttpTransport())->willTimeOutOnConnect()->willReturn(200, '<yml_catalog/>');
        $request = self::search();

        $response = (new RetryOnConnectTimeoutTransport($inner))->send($request);

        self::assertSame(200, $response->statusCode());
        self::assertSame([$request, $request], $inner->requests());
    }

    public function testHeadIsRetried(): void
    {
        $inner = (new FakeHttpTransport())->willTimeOutOnConnect()->willReturn(200, '');
        $request = new HttpRequest('HEAD', self::URL);

        (new RetryOnConnectTimeoutTransport($inner))->send($request);

        self::assertSame([$request, $request], $inner->requests());
    }

    public function testGivesUpAfterRetriesKeepsClass(): void
    {
        $inner = (new FakeHttpTransport())->willTimeOutOnConnect()->willTimeOutOnConnect()->willTimeOutOnConnect('Connection timed out after 10002 milliseconds');

        try {
            (new RetryOnConnectTimeoutTransport($inner, 2))->send(self::search());
            self::fail('Ожидалось исключение');
        } catch (ConnectTimeoutException $e) {
            $url = self::URL . '?_gs_at=***&l=10&p=1';
            self::assertSame(
                sprintf('GET %s: превышено время установки соединения (cURL 28: Connection timed out after 10002 milliseconds); попыток: 3', $url),
                $e->getMessage(),
            );
            $previous = $e->getPrevious();
            self::assertInstanceOf(ConnectTimeoutException::class, $previous);
            self::assertStringContainsString('10002 milliseconds', $previous->getMessage());
            self::assertSame('GET', $e->method());
            self::assertSame($url, $e->url());
            self::assertSame(28, $e->curlErrorCode());
        }
        self::assertCount(3, $inner->requests());
    }

    public function testZeroRetriesRethrowsSameInstance(): void
    {
        $timeout = new ConnectTimeoutException('GET …: превышено время установки соединения', 'GET', self::URL, 28);
        $inner = (new FakeHttpTransport())->willThrow($timeout);

        try {
            (new RetryOnConnectTimeoutTransport($inner, 0))->send(self::search());
            self::fail('Ожидалось исключение');
        } catch (ConnectTimeoutException $e) {
            self::assertSame($timeout, $e);
        }
        self::assertCount(1, $inner->requests());
    }

    public function testPostIsNeverRetried(): void
    {
        $timeout = new ConnectTimeoutException('POST …: превышено время установки соединения', 'POST', 'https://www.gdeslon.ru/api/v1/lost-orders/', 28);
        $inner = (new FakeHttpTransport())->willThrow($timeout);
        $request = HttpRequest::post('https://www.gdeslon.ru/api/v1/lost-orders/', "--b\r\n\r\n--b--\r\n", ['Content-Type' => 'multipart/form-data; boundary=b']);

        try {
            (new RetryOnConnectTimeoutTransport($inner, 5))->send($request);
            self::fail('Ожидалось исключение');
        } catch (ConnectTimeoutException $e) {
            self::assertSame($timeout, $e);
        }
        self::assertSame([$request], $inner->requests());
    }

    /**
     * @return iterable<string, array{TransportException}>
     */
    public static function notRetried(): iterable
    {
        yield 'таймаут чтения' => [new TimeoutException('GET …: превышено время ожидания', 'GET', self::URL, 28)];
        yield 'cURL 7' => [new TransportException('GET …: ошибка соединения', 'GET', self::URL, 7)];
        yield 'cURL 56' => [new TransportException('GET …: ошибка соединения', 'GET', self::URL, 56)];
    }

    #[DataProvider('notRetried')]
    public function testOtherTransportErrorsAreNotRetried(TransportException $error): void
    {
        $inner = (new FakeHttpTransport())->willThrow($error);

        try {
            (new RetryOnConnectTimeoutTransport($inner))->send(self::search());
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertSame($error, $e);
        }
        self::assertCount(1, $inner->requests());
    }

    public function testErrorResponsesAreReturnedNotRetried(): void
    {
        $inner = (new FakeHttpTransport())->willReturn(500, 'Something broken!')->willReturn(420, 'Rate limit exceeded!');
        $transport = new RetryOnConnectTimeoutTransport($inner);

        self::assertSame(500, $transport->send(self::search())->statusCode());
        self::assertSame(420, $transport->send(self::search())->statusCode());
        self::assertCount(2, $inner->requests());
    }

    #[DataProvider('invalidRetries')]
    public function testRejectsInvalidRetries(int $retries): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Число повторов при таймауте соединения должно быть от 0 до 5, получено %d', $retries));

        new RetryOnConnectTimeoutTransport(new FakeHttpTransport(), $retries);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidRetries(): iterable
    {
        yield 'отрицательное' => [-1];
        yield 'больше 5' => [6];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX];
    }

    public function testGivingUpDoesNotLeakSecretsThroughTrace(): void
    {
        self::requireSensitiveParameter();
        $inner = (new FakeHttpTransport())->willTimeOutOnConnect()->willTimeOutOnConnect();
        $request = HttpRequest::get('https://gdeslon.ru/api/orders/', ['_gs_at' => self::TOKEN], ['Authorization' => 'Basic ' . base64_encode('1234:test-api-key-0123')]);

        $e = self::catchWithTraceArgs(static fn () => (new RetryOnConnectTimeoutTransport($inner))->send($request), ConnectTimeoutException::class);

        self::assertTraceHidesSecrets($e, self::TOKEN, base64_encode('1234:test-api-key-0123'));
    }

    private static function search(): HttpRequest
    {
        return HttpRequest::get(self::URL, ['_gs_at' => self::TOKEN, 'l' => 10, 'p' => 1]);
    }
}
