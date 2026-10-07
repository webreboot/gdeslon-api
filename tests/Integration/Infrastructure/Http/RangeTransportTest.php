<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Tests\Support\FakeRangeServer;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class RangeTransportTest extends TestCase
{
    private const URL = 'https://api.gdeslon.ru/gdeslon-categories.json';
    private const SIZE = 159451;

    public function testSmallBodyInOneRequest(): void
    {
        $server = new FakeRangeServer(str_repeat('x', 500));

        $response = (new RangeTransport($server))->send(HttpRequest::get(self::URL));

        self::assertSame(['bytes=0-16383'], $server->ranges());
        self::assertSame('close', $server->requests()[0]->header('Connection'));
        self::assertSame(200, $response->statusCode());
        self::assertSame(500, strlen($response->body()));
        self::assertSame([], $response->headerValues('content-range'));
        self::assertSame(['500'], $response->headerValues('content-length'));
        self::assertSame(FakeRangeServer::ETAG, $response->header('etag'));
        self::assertSame(['application/json', 'application/json; charset=utf-8'], $response->headerValues('content-type'));
    }

    public function testCategoriesFileInTenParts(): void
    {
        $body = self::body(self::SIZE);
        $server = new FakeRangeServer($body);

        $response = (new RangeTransport($server))->send(HttpRequest::get(self::URL));

        $expected = [];
        for ($start = 0; $start < self::SIZE; $start += 16384) {
            $expected[] = sprintf('bytes=%d-%d', $start, $start + 16383);
        }
        self::assertCount(10, $expected);
        self::assertSame($expected, $server->ranges());
        self::assertSame($body, $response->body());
        self::assertSame(200, $response->statusCode());
        foreach ($server->requests() as $i => $request) {
            self::assertSame('close', $request->header('Connection'));
            self::assertSame($i === 0 ? null : FakeRangeServer::ETAG, $request->header('If-Match'));
        }
    }

    public function testPartBoundaryInsideCyrillicCharacter(): void
    {
        $fixture = Fixtures::read('categories/categories.json');
        $server = new FakeRangeServer($fixture);

        $response = (new RangeTransport($server, chunkSize: 1024))->send(HttpRequest::get(self::URL));

        self::assertCount(3, $server->requests());
        self::assertSame($fixture, $response->body());
    }

    public function testExactMultipleOfChunk(): void
    {
        $server = new FakeRangeServer(self::body(32768));

        (new RangeTransport($server))->send(HttpRequest::get(self::URL));

        self::assertSame(['bytes=0-16383', 'bytes=16384-32767'], $server->ranges());
    }

    public function testServerIgnoringRangeIsPassedThrough(): void
    {
        $server = (new FakeRangeServer(self::body(40000)))->ignoreRange();

        $response = (new RangeTransport($server))->send(HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['l' => 100]));

        self::assertCount(1, $server->requests());
        self::assertSame('bytes=0-16383', $server->requests()[0]->header('Range'));
        self::assertNull($server->requests()[0]->timeout());
        self::assertSame(200, $response->statusCode());
        self::assertSame(40000, strlen($response->body()));
        self::assertSame('text/xml; charset=utf-8', $response->header('content-type'));
    }

    #[DataProvider('errorStatuses')]
    public function testErrorOnFirstPartIsReturnedAsIs(int $status): void
    {
        $server = (new FakeRangeServer(self::body(self::SIZE)))
            ->override(1, new HttpResponse($status, 'ошибка', ['Content-Type' => ['text/html']]));

        $response = (new RangeTransport($server))->send(HttpRequest::get(self::URL));

        self::assertSame($status, $response->statusCode());
        self::assertSame('ошибка', $response->body());
        self::assertCount(1, $server->requests());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function errorStatuses(): iterable
    {
        foreach ([403, 404, 429, 500] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function testNotModifiedIsReturnedAsIsAndConditionalHeadersStayInFirstPart(): void
    {
        $unchanged = new FakeRangeServer(self::body(self::SIZE));
        $request = HttpRequest::get(self::URL, [], ['If-None-Match' => FakeRangeServer::ETAG]);

        self::assertSame(304, (new RangeTransport($unchanged))->send($request)->statusCode());
        self::assertCount(1, $unchanged->requests());

        $changed = new FakeRangeServer(self::body(self::SIZE), '"new"');
        (new RangeTransport($changed))->send($request);

        self::assertCount(10, $changed->requests());
        self::assertSame(FakeRangeServer::ETAG, $changed->requests()[0]->header('If-None-Match'));
        self::assertNull($changed->requests()[1]->header('If-None-Match'));
    }

    public function testRangeNotSatisfiableOnFirstPartFallsBackToPlainRequest(): void
    {
        $server = new FakeRangeServer('');

        $response = (new RangeTransport($server))->send(HttpRequest::get(self::URL));

        self::assertSame(['bytes=0-16383', null], $server->ranges());
        self::assertSame(200, $response->statusCode());
        self::assertSame('', $response->body());
    }

    #[DataProvider('passThroughRequests')]
    public function testOtherRequestsArePassedUnchanged(HttpRequest $request): void
    {
        $server = new FakeRangeServer('ok');

        (new RangeTransport($server))->send($request);

        self::assertSame([$request], $server->requests());
    }

    /**
     * @return iterable<string, array{HttpRequest}>
     */
    public static function passThroughRequests(): iterable
    {
        yield 'POST' => [new HttpRequest('POST', self::URL)];
        yield 'HEAD' => [new HttpRequest('HEAD', self::URL)];
        yield 'другой хост' => [HttpRequest::get('https://www.gdeslon.ru/api/users/shops.xml', ['api_token' => 'secret'])];
        yield 'уже с Range' => [HttpRequest::get(self::URL, [], ['range' => 'bytes=0-9'])];
    }

    public function testResourceChangedBetweenPartsByEtag(): void
    {
        $server = (new FakeRangeServer(self::body(self::SIZE)))->changeAfter(1);
        $request = HttpRequest::get(self::URL, ['_gs_at' => 'secret']);

        try {
            (new RangeTransport($server))->send($request);
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertStringContainsString('изменился', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->url());
        }
    }

    public function testResourceChangedBetweenPartsBySize(): void
    {
        $server = (new FakeRangeServer(self::body(self::SIZE)))->override(2, self::part('', 16384, 32767, 160000));

        $this->expectExceptionObject(new TransportException('изменился', 'GET', self::URL));
        $this->expectExceptionMessage('изменился');

        (new RangeTransport($server))->send(HttpRequest::get(self::URL));
    }

    public function testPreconditionFailedMeansResourceChanged(): void
    {
        $server = (new FakeRangeServer(self::body(self::SIZE)))->changeAfter(1, honourIfMatch: true);

        try {
            (new RangeTransport($server))->send(HttpRequest::get(self::URL));
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertStringContainsString('изменился', $e->getMessage());
            self::assertCount(2, $server->requests());
        }
    }

    #[DataProvider('brokenSecondParts')]
    public function testUnexpectedSecondPart(HttpResponse $secondPart): void
    {
        $server = (new FakeRangeServer(self::body(self::SIZE)))->override(2, $secondPart);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('неожиданный ответ на часть bytes=16384-32767');

        (new RangeTransport($server))->send(HttpRequest::get(self::URL));
    }

    /**
     * @return iterable<string, array{HttpResponse}>
     */
    public static function brokenSecondParts(): iterable
    {
        $etag = ['ETag' => [FakeRangeServer::ETAG], 'Last-Modified' => [FakeRangeServer::LAST_MODIFIED]];
        yield '200 вместо 206' => [new HttpResponse(200, 'x', $etag)];
        yield '206 без Content-Range' => [new HttpResponse(206, str_repeat('x', 16384), $etag)];
        yield 'multipart' => [new HttpResponse(206, '--x', $etag + ['Content-Type' => ['multipart/byteranges; boundary=x']])];
        yield 'сжатая часть' => [new HttpResponse(206, str_repeat('x', 16384), $etag + [
            'Content-Range' => ['bytes 16384-32767/' . self::SIZE],
            'Content-Encoding' => ['gzip'],
        ])];
        yield '503' => [new HttpResponse(503, 'busy', [])];
        yield 'начало не то' => [self::part(str_repeat('x', 16384), 16000, 32383, self::SIZE)];
        yield 'тело короче диапазона' => [self::part(str_repeat('x', 100), 16384, 32767, self::SIZE)];
    }

    public function testWeakEtagIsNotSentAsIfMatchButChangeIsDetected(): void
    {
        $server = new FakeRangeServer(self::body(self::SIZE), 'W/"x"');
        (new RangeTransport($server))->send(HttpRequest::get(self::URL));
        self::assertNull($server->requests()[1]->header('If-Match'));

        $changing = (new FakeRangeServer(self::body(self::SIZE), 'W/"x"'))->changeAfter(1);
        $this->expectExceptionMessage('изменился');
        (new RangeTransport($changing))->send(HttpRequest::get(self::URL));
    }

    public function testLastModifiedAloneIsEnoughToAssemble(): void
    {
        $body = self::body(40000);
        $server = new FakeRangeServer($body, null);

        $response = (new RangeTransport($server))->send(HttpRequest::get(self::URL));

        self::assertSame($body, $response->body());
        self::assertNull($server->requests()[1]->header('If-Match'));
    }

    public function testPartsWithoutValidatorsAreNotAssembled(): void
    {
        $body = self::body(40000);
        $server = new FakeRangeServer($body, null, null);

        $response = (new RangeTransport($server))->send(HttpRequest::get(self::URL));

        self::assertSame(['bytes=0-16383', null], $server->ranges());
        self::assertNull($server->requests()[1]->header('If-Match'));
        self::assertSame(200, $response->statusCode());
        self::assertSame($body, $response->body());
    }

    public function testTimedOutPartIsRetriedWithHalfSize(): void
    {
        $body = self::body(self::SIZE);
        $server = (new FakeRangeServer($body))->failPart(32768, self::timeout());

        $response = (new RangeTransport($server))->send(HttpRequest::get(self::URL));

        $ranges = $server->ranges();
        self::assertSame(['bytes=0-16383', 'bytes=16384-32767', 'bytes=32768-49151', 'bytes=32768-40959', 'bytes=40960-49151'], array_slice($ranges, 0, 5));
        self::assertSame($body, $response->body());
    }

    public function testGivesUpAfterRetriesAndNeverGoesBelowMinimum(): void
    {
        $timeout = self::timeout();
        $server = (new FakeRangeServer(self::body(self::SIZE)))->failPart(32768, $timeout, 10);

        try {
            (new RangeTransport($server))->send(HttpRequest::get(self::URL, ['_gs_at' => 'secret']));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame(28, $e->curlErrorCode());
            self::assertSame($timeout, $e->getPrevious());
            self::assertStringContainsString('на байте 32768 из 159451 после 3 попыток', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertSame(['bytes=32768-49151', 'bytes=32768-40959', 'bytes=32768-36863'], array_slice($server->ranges(), 2));
        }

        $tiny = (new FakeRangeServer(self::body(20000)))->failPart(4096, self::timeout(), 3);
        try {
            (new RangeTransport($tiny, chunkSize: 4096, retries: 3))->send(HttpRequest::get(self::URL));
        } catch (TimeoutException) {
        }
        self::assertSame(['bytes=4096-8191', 'bytes=4096-8191', 'bytes=4096-8191', 'bytes=4096-8191'], array_slice($tiny->ranges(), 1, 4));
    }

    public function testOtherTransportErrorsAreRetriedWithSameSize(): void
    {
        $body = self::body(self::SIZE);
        $broken = new TransportException('обрыв', 'GET', self::URL, 18);
        $server = (new FakeRangeServer($body))->failPart(16384, $broken, 2);

        self::assertSame($body, (new RangeTransport($server))->send(HttpRequest::get(self::URL))->body());
        self::assertSame(['bytes=16384-32767', 'bytes=16384-32767', 'bytes=16384-32767'], array_slice($server->ranges(), 1, 3));

        $failing = (new FakeRangeServer($body))->failPart(16384, $broken, 3);
        try {
            (new RangeTransport($failing))->send(HttpRequest::get(self::URL));
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame(18, $e->curlErrorCode());
        }
    }

    public function testFirstPartFailureIsNotRetried(): void
    {
        $timeout = self::timeout();
        $server = (new FakeRangeServer(self::body(40000)))->ignoreRange()->failPart(0, $timeout, 3);

        try {
            (new RangeTransport($server))->send(HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['l' => 100]));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e, 'исходное исключение транспорта, без обёртки «загрузка частями»');
        }
        self::assertCount(1, $server->requests());

        $refused = new TransportException('отказ', 'GET', self::URL, 7);
        $unreachable = (new FakeRangeServer(self::body(40000)))->failPart(0, $refused, 3);
        try {
            (new RangeTransport($unreachable))->send(HttpRequest::get(self::URL));
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertSame($refused, $e);
        }
        self::assertCount(1, $unreachable->requests());
    }

    public function testTooLargeResponseIsRejectedAfterFirstPart(): void
    {
        $server = (new FakeRangeServer('x'))->override(1, self::part(str_repeat('x', 16384), 0, 16383, 33554433));

        try {
            (new RangeTransport($server))->send(HttpRequest::get(self::URL));
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertStringContainsString('слишком большой', $e->getMessage());
            self::assertCount(1, $server->requests());
        }
    }

    /**
     * @param array{int, int, float, list<string>} $arguments
     */
    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidSettings(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RangeTransport(new FakeRangeServer(''), ...$arguments);
    }

    /**
     * @return iterable<string, array{array{int, int, float, list<string>}}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'часть меньше 1 КиБ' => [[1023, 2, 10.0, ['api.gdeslon.ru']]];
        yield 'отрицательные повторы' => [[16384, -1, 10.0, ['api.gdeslon.ru']]];
        yield 'нулевой таймаут части' => [[16384, 2, 0.0, ['api.gdeslon.ru']]];
        yield 'пустой список хостов' => [[16384, 2, 10.0, []]];
        yield 'пустой хост' => [[16384, 2, 10.0, ['']]];
    }

    public function testPartTimeoutAppliesFromSecondPart(): void
    {
        $server = new FakeRangeServer(self::body(40000));

        (new RangeTransport($server, partTimeout: 7.5))->send(HttpRequest::get(self::URL));

        self::assertSame([null, 7.5, 7.5], array_map(static fn (HttpRequest $r): ?float => $r->timeout(), $server->requests()));
    }

    public function testOriginalHeadersAndQueryAreSentWithEveryPart(): void
    {
        $server = new FakeRangeServer(self::body(40000));
        $request = HttpRequest::get(self::URL, ['_gs_at' => 'secret'], ['Authorization' => 'Bearer t', 'Accept' => 'application/json']);

        (new RangeTransport($server))->send($request);

        foreach ($server->requests() as $part) {
            self::assertSame('Bearer t', $part->header('Authorization'));
            self::assertSame('application/json', $part->header('Accept'));
            self::assertSame(['_gs_at' => 'secret'], $part->query());
        }
    }

    private static function body(int $length): string
    {
        return substr(str_repeat('{"категория":"Всё для шитья"}', intdiv($length, 20) + 1), 0, $length);
    }

    private static function timeout(): TimeoutException
    {
        return new TimeoutException('таймаут', 'GET', self::URL, 28);
    }

    private static function part(string $body, int $start, int $end, int $total): HttpResponse
    {
        return new HttpResponse(206, $body, [
            'ETag' => [FakeRangeServer::ETAG],
            'Last-Modified' => [FakeRangeServer::LAST_MODIFIED],
            'Content-Range' => [sprintf('bytes %d-%d/%d', $start, $end, $total)],
        ]);
    }
}
