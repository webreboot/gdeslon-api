<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Catalog;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpProductCatalog;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class HttpProductCatalogTest extends TestCase
{
    private const URL = 'https://api.gdeslon.ru/api/search.xml';
    private const TOKEN = 'secret-token';

    public function testDefaultSearch(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search.xml'));

        $result = self::catalog($transport)->search(new SearchCriteria());

        self::assertCount(8, $result);
        self::assertCount(1, $transport->requests());
        $request = $transport->lastRequest();
        self::assertSame('GET', $request->method());
        self::assertSame(self::URL, $request->url());
        self::assertSame(['_gs_at' => self::TOKEN, 'l' => 10, 'p' => 1], $request->query());
        self::assertNull($request->header('Authorization'));
    }

    public function testAllCriteriaAreSent(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search-empty.xml'));
        $criteria = new SearchCriteria(
            query: 'платье',
            merchants: [107054, 111211],
            excludedMerchants: [82012],
            categories: [26, 349],
            excludedCategories: [1],
            articles: ['578237', '434967'],
            limit: 20,
            page: 2,
            sort: OfferSort::Price,
            parkedDomain: 'http://example.com',
        );

        self::catalog($transport)->search($criteria);

        $request = $transport->lastRequest();
        self::assertSame([
            '_gs_at' => self::TOKEN,
            'l' => 20,
            'p' => 2,
            'q' => 'платье',
            'm' => '107054,111211',
            'no_m' => '82012',
            'tid' => '26,349',
            'no_tid' => '1',
            'articles' => '578237,434967',
            'order' => 'price',
            'parked_domain_name' => 'http://example.com',
        ], $request->query());
        self::assertStringContainsString('m=107054%2C111211', $request->uri());
        self::assertStringContainsString('q=%D0%BF%D0%BB%D0%B0%D1%82%D1%8C%D0%B5', $request->uri());
        self::assertStringContainsString('q=iphone%20-pink', HttpRequest::get(self::URL, ['q' => 'iphone -pink'])->uri());
    }

    public function testRejectedToken(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(403, 'This affiliate token does not exists');

        try {
            self::catalog($transport)->search(new SearchCriteria());
            self::fail('Ожидалось исключение');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('_gs_at=***', $e->url());
            self::assertNoToken($e);
        }
    }

    public function testMalformedTokenIsAuthenticationError(): void
    {
        // реальный ответ на токен неверного формата: 404 с ошибкой валидации _gs_at и значением токена в теле
        $transport = (new FakeHttpTransport())->willReturn(
            404,
            "There have been validation errors: [ { param: '_gs_at',     msg: 'Invalid value',     value: '" . self::TOKEN . "' } ]",
            ['Content-Type' => ['text/html; charset=utf-8']],
        );

        try {
            self::catalog($transport)->search(new SearchCriteria());
            self::fail('Ожидалось исключение');
        } catch (AuthenticationException $e) {
            self::assertSame(404, $e->statusCode());
            self::assertStringContainsString('токен XML API', $e->getMessage());
            self::assertNoToken($e);
        }
    }

    public function testOtherValidationErrorStaysHttpError(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(404, "There have been validation errors: [ { param: 'l', msg: 'Invalid value', value: 'x' } ]");

        try {
            self::catalog($transport)->search(new SearchCriteria());
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertNotInstanceOf(AuthenticationException::class, $e);
            self::assertSame(404, $e->statusCode());
        }
    }

    public function testServerError(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(500, 'Something broken!');

        try {
            self::catalog($transport)->search(new SearchCriteria());
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame(500, $e->statusCode());
            self::assertNoToken($e);
        }
    }

    public function testTimeoutGetsHintToReduceLimit(): void
    {
        $original = new TimeoutException(
            'GET https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=100&p=1: превышено время ожидания (cURL 28: Operation timed out with 16011 bytes received)',
            'GET',
            'https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=100&p=1',
            28,
        );
        $transport = (new FakeHttpTransport())->willThrow($original);

        try {
            self::catalog($transport)->search(new SearchCriteria(limit: 100));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertStringContainsString($original->getMessage(), $e->getMessage());
            self::assertStringContainsString('limit', $e->getMessage());
            self::assertStringContainsString('100', $e->getMessage());
            self::assertSame('GET', $e->method());
            self::assertSame($original->url(), $e->url());
            self::assertSame(28, $e->curlErrorCode());
            self::assertSame($original, $e->getPrevious());
            self::assertNoToken($e);
        }
        self::assertCount(1, $transport->requests(), 'запрос не повторяется и limit не меняется');
    }

    public function testOtherTransportErrorsPassThrough(): void
    {
        $refused = new TransportException('отказ', 'GET', self::URL, 7);
        $transport = (new FakeHttpTransport())->willThrow($refused);

        try {
            self::catalog($transport)->search(new SearchCriteria());
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertSame($refused, $e);
        }
    }

    public function testBrokenResponses(): void
    {
        foreach (['<!DOCTYPE html><html>сервис недоступен</html>', '', substr(Fixtures::read('search/search.xml'), 0, 6000)] as $body) {
            $transport = (new FakeHttpTransport())->willReturn(200, $body);
            try {
                self::catalog($transport)->search(new SearchCriteria());
                self::fail('Ожидалось исключение');
            } catch (UnexpectedResponseException $e) {
                self::assertNoToken($e);
            }
        }
    }

    public function testSearchWithoutTokenFailsBeforeRequest(): void
    {
        $transport = new FakeHttpTransport();

        try {
            (new HttpProductCatalog(new ApiClient($transport)))->search(new SearchCriteria());
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('токен XML API', $e->getMessage());
        }
        self::assertSame([], $transport->requests());
    }

    public function testTokenIsHiddenFromDumpsAndSerialization(): void
    {
        $catalog = self::catalog(new FakeHttpTransport());

        ob_start();
        var_dump($catalog);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString(self::TOKEN, print_r($catalog, true));

        try {
            serialize($catalog);
            self::fail('Ожидалось исключение');
        } catch (GdeSlonException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testWorksBehindRangeTransport(): void
    {
        // Express на search.xml игнорирует Range и отвечает 200 целиком
        $express = new class (Fixtures::read('search/search.xml')) implements HttpTransport {
            /** @var list<HttpRequest> */
            public array $requests = [];

            public function __construct(private readonly string $body)
            {
            }

            public function send(HttpRequest $request): HttpResponse
            {
                $this->requests[] = $request;

                return new HttpResponse(200, $this->body, ['Content-Type' => ['text/xml; charset=utf-8'], 'X-Powered-By' => ['Express']]);
            }
        };

        $result = (new HttpProductCatalog(new ApiClient(new RangeTransport($express)), self::TOKEN))->search(new SearchCriteria());

        self::assertCount(8, $result);
        self::assertCount(1, $express->requests);
        self::assertSame('bytes=0-16383', $express->requests[0]->header('Range'));
        self::assertSame('close', $express->requests[0]->header('Connection'));
    }

    private static function catalog(FakeHttpTransport $transport): HttpProductCatalog
    {
        return new HttpProductCatalog(new ApiClient($transport), self::TOKEN);
    }

    private static function assertNoToken(\Throwable $e): void
    {
        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        if ($e instanceof HttpException) {
            self::assertStringNotContainsString(self::TOKEN, $e->url());
            self::assertStringNotContainsString(self::TOKEN, $e->responseSnippet());
        }
        if ($e instanceof TransportException) {
            self::assertStringNotContainsString(self::TOKEN, $e->url());
        }
    }
}
