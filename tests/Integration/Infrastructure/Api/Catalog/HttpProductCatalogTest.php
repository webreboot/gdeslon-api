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
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\SplitOnTimeoutProductCatalog;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class HttpProductCatalogTest extends TestCase
{
    private const URL = 'https://api.gdeslon.ru/api/search.xml';
    private const TOKEN = 'secret-token';

    public function testDefaultSearch(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search.xml'));

        $result = self::catalog($transport)->search(new SearchCriteria());

        self::assertCount(7, $result);
        self::assertCount(1, $result->skipped());
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

    public function testLongCategoryListIsSentAsOneCommaSeparatedTid(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search-empty.xml'));
        $ids = range(1, 600);

        self::catalog($transport)->search(new SearchCriteria(categories: $ids));

        self::assertCount(1, $transport->requests());
        $request = $transport->lastRequest();
        self::assertSame(implode(',', $ids), $request->query()['tid'] ?? null);
        self::assertSame(1, substr_count($request->uri(), 'tid='));
        self::assertStringContainsString('tid=1%2C2%2C3%2C', $request->uri());
    }

    public function testUriTooLongGetsHintToShortenFilters(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(414, Fixtures::read('search/error-414.html'), ['Content-Type' => ['text/html']]);

        try {
            self::catalog($transport)->search(new SearchCriteria(query: 'тренч', categories: range(1, 1389)));
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            $request = $transport->lastRequest();
            self::assertNotInstanceOf(AuthenticationException::class, $e);
            self::assertSame(414, $e->statusCode());
            self::assertSame(414, $e->getCode());
            self::assertSame('GET', $e->method());
            self::assertSame($request->maskedUri(), $e->url());
            self::assertStringContainsString('414 Request-URI Too Large', $e->responseSnippet());
            self::assertInstanceOf(HttpException::class, $e->getPrevious());
            self::assertStringStartsWith('GET ' . self::URL . ': HTTP 414', $e->getMessage());
            self::assertStringNotContainsString('?', $e->getMessage());
            self::assertStringNotContainsString('_gs_at', $e->getMessage());
            self::assertStringContainsString(sprintf('%d байт', strlen($request->uri())), $e->getMessage());
            foreach (['категорий', 'магазинов', 'артикулов'] as $filter) {
                self::assertStringContainsString($filter, $e->getMessage());
            }
            self::assertNoToken($e);
        }
        self::assertCount(1, $transport->requests());
    }

    public function testOtherClientErrorsKeepGenericMessage(): void
    {
        foreach ([400, 413] as $status) {
            $transport = (new FakeHttpTransport())->willReturn($status, 'Bad Request');

            try {
                self::catalog($transport)->search(new SearchCriteria(categories: [1113]));
                self::fail('Ожидалось исключение');
            } catch (HttpException $e) {
                self::assertSame($status, $e->statusCode());
                self::assertSame(sprintf('GET %s: HTTP %d', $transport->lastRequest()->maskedUri(), $status), $e->getMessage());
                self::assertNull($e->getPrevious());
                self::assertNoToken($e);
            }
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

    public function testTimeoutPassesThroughUnchanged(): void
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
            self::assertSame($original, $e);
        }
        self::assertCount(1, $transport->requests());
    }

    public function testTimedOutPageIsReloadedInSmallerPages(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(28, 'Operation timed out after 30000 milliseconds with 16011 bytes received')
            ->willReturn(200, Fixtures::read('search/search-l5-p1.xml'))
            ->willReturn(200, Fixtures::read('search/search-l5-p2.xml'));

        $result = self::splitting($transport)->search(new SearchCriteria(query: 'платье', limit: 10));

        self::assertSame(
            [[10, 1], [5, 1], [5, 2]],
            array_map(static fn (HttpRequest $r): array => [$r->query()['l'] ?? null, $r->query()['p'] ?? null], $transport->requests()),
        );
        self::assertSame('платье', $transport->lastRequest()->query()['q']);
        self::assertCount(10, $result);
        self::assertSame('4717995399023499000', $result->offers()[0]->id());
        self::assertSame('15344783908528867000', $result->offers()[5]->id());
        self::assertSame([], $result->skipped());
        self::assertSame(8140, $result->total());
        self::assertSame(5, $result->effectiveLimit());
        self::assertTrue($result->isDegraded());
        self::assertSame(10, $result->criteria()->limit());
        self::assertSame(2, $result->nextPage()?->page());
    }

    public function testTimedOutPartIsReloadedPieceByPiece(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(28, 'Operation timed out after 30000 milliseconds with 0 bytes received')
            ->willFail(28, 'Operation timed out after 30000 milliseconds with 0 bytes received');
        for ($page = 1; $page <= 5; $page++) {
            $transport->willReturn(200, Fixtures::read(sprintf('search/search-l1-p%d.xml', $page)));
        }
        $transport->willReturn(200, Fixtures::read('search/search-l5-p2.xml'));

        $result = self::splitting($transport)->search(new SearchCriteria(query: 'платье', limit: 10));

        self::assertSame(
            [[10, 1], [5, 1], [1, 1], [1, 2], [1, 3], [1, 4], [1, 5], [5, 2]],
            array_map(static fn (HttpRequest $r): array => [$r->query()['l'] ?? null, $r->query()['p'] ?? null], $transport->requests()),
        );
        self::assertCount(10, $result);
        self::assertSame('4717995399023499000', $result->offers()[0]->id());
        self::assertSame('16501982560061594000', $result->offers()[4]->id());
        self::assertSame('15344783908528867000', $result->offers()[5]->id());
        self::assertMatchesRegularExpression('/\p{Cyrillic}/u', $result->offers()[0]->name());
        self::assertSame(8203, $result->total());
        self::assertSame(1, $result->effectiveLimit());
        self::assertTrue($result->isDegraded());
        self::assertSame(2, $result->nextPage()?->page());
    }

    public function testTimeoutOfLastPieceHidesToken(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willFail(28, 'Operation timed out')
            ->willFail(28, 'Operation timed out');

        try {
            self::splitting($transport)->search(new SearchCriteria(limit: 10));
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertStringContainsString('часть l=1&p=1 (оффер 1) превысила время ожидания, делить её дальше некуда', $e->getMessage());
            self::assertStringContainsString('_gs_at=***&l=1&p=1', $e->url());
            self::assertSame(28, $e->curlErrorCode());
            $previous = $e->getPrevious();
            self::assertInstanceOf(TimeoutException::class, $previous);
            self::assertNoToken($e);
            self::assertNoToken($previous);
        }
        self::assertCount(3, $transport->requests());
    }

    public function testRateLimitDuringSplitPassesThrough(): void
    {
        $transport = (new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willReturn(200, Fixtures::read('search/search-l5-p1.xml'))
            ->willReturn(420, 'Rate limit exceeded!', ['Content-Type' => ['text/html; charset=utf-8']]);

        try {
            self::splitting($transport)->search(new SearchCriteria(query: 'платье', limit: 10));
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame(420, $e->statusCode());
            self::assertSame('Rate limit exceeded!', $e->responseSnippet());
            self::assertNull($e->getPrevious());
            self::assertNoToken($e);
        }
        self::assertCount(3, $transport->requests());
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

        self::assertCount(7, $result);
        self::assertCount(1, $result->skipped());
        self::assertCount(1, $express->requests);
        self::assertSame('bytes=0-16383', $express->requests[0]->header('Range'));
        self::assertSame('close', $express->requests[0]->header('Connection'));
    }

    private static function catalog(FakeHttpTransport $transport): HttpProductCatalog
    {
        return new HttpProductCatalog(new ApiClient($transport), self::TOKEN);
    }

    private static function splitting(FakeHttpTransport $transport): SplitOnTimeoutProductCatalog
    {
        return new SplitOnTimeoutProductCatalog(self::catalog($transport));
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
