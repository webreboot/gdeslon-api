<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;

final class FakeHttpTransportTest extends TestCase
{
    private const URL = 'https://api.gdeslon.ru/api/search.xml';

    public function testResponsesAreReturnedInOrder(): void
    {
        $transport = (new FakeHttpTransport())
            ->willReturn(200, 'первый')
            ->willReturn(404, 'второй');

        self::assertSame('первый', $transport->send(HttpRequest::get(self::URL))->body());
        $second = $transport->send(HttpRequest::get(self::URL));
        self::assertSame(404, $second->statusCode());
        self::assertSame('второй', $second->body());
    }

    public function testBodyIsReturnedByteForByte(): void
    {
        $body = "\xEF\xBB\xBF<offers>\r\n<offer>\0</offer>\n</offers> ";
        $transport = (new FakeHttpTransport())->willReturn(206, $body, ['ETag' => ['"a"'], 'Set-Cookie' => ['x=1', 'y=2']]);

        $response = $transport->send(HttpRequest::get(self::URL));

        self::assertSame($body, $response->body());
        self::assertSame(206, $response->statusCode());
        self::assertSame('"a"', $response->header('etag'));
        self::assertSame(['x=1', 'y=2'], $response->headerValues('Set-Cookie'));
    }

    public function testHeaderGivenAsStringIsKept(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(304, '', ['ETag' => '"v1"', 'Vary' => ['Accept', 'Range']]);

        $response = $transport->send(HttpRequest::get(self::URL));

        self::assertSame('"v1"', $response->header('etag'));
        self::assertSame(['"v1"'], $response->headerValues('ETag'));
        self::assertSame(['Accept', 'Range'], $response->headerValues('vary'));
    }

    public function testWillThrowRethrowsSameInstance(): void
    {
        $failure = new TransportException('обрыв', 'GET', self::URL, 56);
        $transport = (new FakeHttpTransport())->willThrow($failure);

        try {
            $transport->send(HttpRequest::get(self::URL));
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertSame($failure, $e);
        }
    }

    public function testWillFailMapsCurlCodes(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willFail(7);

        try {
            $transport->send(HttpRequest::get(self::URL));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame(28, $e->curlErrorCode());
            self::assertStringContainsString('Operation timed out', $e->getMessage());
        }

        try {
            $transport->send(HttpRequest::get(self::URL));
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame(7, $e->curlErrorCode());
            self::assertStringContainsString('Failed to connect', $e->getMessage());
        }
    }

    public function testWillFailCreatesExceptionInsideSend(): void
    {
        $transport = (new FakeHttpTransport())->willFail(7)->willFail(7);
        $failures = [];
        foreach (['https://api.gdeslon.ru/api/search.xml', 'https://www.gdeslon.ru/api/users/shops.xml'] as $url) {
            try {
                $transport->send(HttpRequest::get($url, ['_gs_at' => 'secret-token', 'q' => 'iphone']));
                self::fail('Ожидалось исключение');
            } catch (TransportException $e) {
                $failures[] = $e;
            }
        }

        self::assertNotSame($failures[0], $failures[1]);
        self::assertSame('GET', $failures[0]->method());
        self::assertStringStartsWith('https://api.gdeslon.ru/api/search.xml?', $failures[0]->url());
        self::assertStringStartsWith('https://www.gdeslon.ru/api/users/shops.xml?', $failures[1]->url());
        foreach ($failures as $failure) {
            self::assertStringContainsString('q=iphone', $failure->getMessage());
            self::assertStringNotContainsString('secret-token', $failure->getMessage());
            self::assertStringNotContainsString('secret-token', $failure->url());
        }
    }

    public function testEmptyQueueThrowsLogicExceptionWithoutToken(): void
    {
        $transport = new FakeHttpTransport();
        $request = HttpRequest::get(self::URL, ['_gs_at' => 'secret-token', 'q' => 'iphone']);

        try {
            $transport->send($request);
            self::fail('Ожидалось исключение');
        } catch (\LogicException $e) {
            self::assertNotInstanceOf(GdeSlonException::class, $e);
            self::assertStringContainsString('GET ' . $request->maskedUri(), $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testRequestsRecordFailedCalls(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(7)
            ->willThrow(new TimeoutException('таймаут', 'GET', self::URL, 28))
            ->willReturn(200, 'ok');
        $sent = [HttpRequest::get(self::URL, ['q' => '1']), HttpRequest::get(self::URL, ['q' => '2']), HttpRequest::post(self::URL, 'тело'), HttpRequest::get(self::URL, ['q' => '4'])];

        foreach ($sent as $request) {
            try {
                $transport->send($request);
            } catch (TransportException | \LogicException) {
            }
        }

        self::assertSame($sent, $transport->requests());
        self::assertSame($sent[3], $transport->lastRequest());
    }

    public function testLastRequestWithoutRequestsThrows(): void
    {
        $this->expectException(\LogicException::class);

        (new FakeHttpTransport())->willReturn(200, 'ok')->lastRequest();
    }

    public function testFluentMethodsReturnSameInstance(): void
    {
        $transport = new FakeHttpTransport();

        self::assertSame($transport, $transport->willReturn(200, ''));
        self::assertSame($transport, $transport->willThrow(new TransportException('x', 'GET', self::URL)));
        self::assertSame($transport, $transport->willFail(7));
        self::assertSame([], $transport->requests());
    }
}
