<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\TraceSecrets;

final class ApiClientTest extends TestCase
{
    use TraceSecrets;

    private const SECRET = 'secret-token';

    private const TOKEN = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, \Closure(ApiClient, HttpRequest): mixed, class-string<\Throwable>}>
     */
    public static function failingCalls(): iterable
    {
        yield 'requestJson, 401' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(401, '{"detail":"Недопустимый токен."}'),
            static fn (ApiClient $c, HttpRequest $r) => $c->requestJson($r),
            AuthenticationException::class,
        ];
        yield 'request, 403' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(403, 'This affiliate token does not exists'),
            static fn (ApiClient $c, HttpRequest $r) => $c->request($r),
            AuthenticationException::class,
        ];
        yield 'requestAllowing, 500' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(500, '<h1>Server Error</h1>'),
            static fn (ApiClient $c, HttpRequest $r) => $c->requestAllowing($r, 400),
            HttpException::class,
        ];
        yield 'fetch, 502' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(502, 'Bad Gateway'),
            static fn (ApiClient $c, HttpRequest $r) => $c->fetch($r),
            HttpException::class,
        ];
        yield 'request, 304' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(304, ''),
            static fn (ApiClient $c, HttpRequest $r) => $c->request($r),
            HttpException::class,
        ];
        yield 'requestJson, битый JSON' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, '{'),
            static fn (ApiClient $c, HttpRequest $r) => $c->requestJson($r),
            UnexpectedResponseException::class,
        ];
        yield 'ошибка транспорта' => [
            static fn (FakeHttpTransport $t) => $t->willFail(7),
            static fn (ApiClient $c, HttpRequest $r) => $c->requestJson($r),
            TransportException::class,
        ];
        yield 'таймаут' => [
            static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out'),
            static fn (ApiClient $c, HttpRequest $r) => $c->request($r),
            TimeoutException::class,
        ];
    }

    /**
     * @return list<HttpRequest>
     */
    private static function requestsWithSecrets(): array
    {
        return [
            HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['q' => 'x', '_gs_at' => self::TOKEN]),
            HttpRequest::get('https://gdeslon.ru/api/v1/lost-orders/', [], ['Authorization' => 'Bearer ' . self::TOKEN]),
        ];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param \Closure(ApiClient, HttpRequest): mixed        $call
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('failingCalls')]
    public function testSecretsAreHiddenFromTraceArguments(\Closure $response, \Closure $call, string $expected): void
    {
        self::requireSensitiveParameter();

        foreach (self::requestsWithSecrets() as $request) {
            $client = new ApiClient($response(new FakeHttpTransport()));
            $e = self::catchWithTraceArgs(static fn () => $call($client, $request), $expected);

            self::assertTraceHidesSecrets($e, self::TOKEN);
        }
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param \Closure(ApiClient, HttpRequest): mixed        $call
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('failingCalls')]
    public function testSecretsAreNotSerializedWithTrace(\Closure $response, \Closure $call, string $expected): void
    {
        foreach (self::requestsWithSecrets() as $request) {
            $client = new ApiClient($response(new FakeHttpTransport()));
            $e = self::catchWithTraceArgs(static fn () => $call($client, $request), $expected);

            self::assertSerializedTraceHidesSecrets($e, self::TOKEN);
            self::assertStringNotContainsString(self::TOKEN, self::fullString($e));
        }
    }

    public function testDecodesJsonObject(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '{"1":{"_id":1,"name":"Подарки"}}');
        $request = HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json');

        $payload = (new ApiClient($transport))->requestJson($request);

        self::assertSame(['1' => ['_id' => 1, 'name' => 'Подарки']], $payload);
        self::assertSame([$request], $transport->requests());
    }

    #[DataProvider('authStatuses')]
    public function testAuthenticationErrors(int $status): void
    {
        $transport = (new FakeHttpTransport())->willReturn($status, '{"detail":"Учетные данные не были предоставлены."}');

        try {
            (new ApiClient($transport))->requestJson(self::requestWithToken());
            self::fail('Ожидалось исключение');
        } catch (AuthenticationException $e) {
            self::assertSame($status, $e->statusCode());
            self::assertSame('https://api.gdeslon.ru/api/search.xml?q=x&_gs_at=***', $e->url());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringContainsString('HTTP ' . $status, $e->getMessage());
            self::assertStringContainsString('Учетные данные не были предоставлены.', $e->responseSnippet());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function authStatuses(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
    }

    #[DataProvider('httpErrorStatuses')]
    public function testOtherHttpErrors(int $status): void
    {
        $body = '<!DOCTYPE html><html><body><pre>Cannot GET /api/search.xml?q=x&_gs_at=' . self::SECRET . '</pre>'
            . str_repeat('x', 1000) . '</body></html>';
        $transport = (new FakeHttpTransport())->willReturn($status, $body);

        try {
            (new ApiClient($transport))->requestJson(self::requestWithToken());
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertNotInstanceOf(AuthenticationException::class, $e);
            self::assertSame($status, $e->statusCode());
            self::assertLessThanOrEqual(500, strlen($e->responseSnippet()));
            self::assertStringContainsString('Cannot GET /api/search.xml?q=x&_gs_at=***', $e->responseSnippet());
            self::assertStringNotContainsString(self::SECRET, $e->responseSnippet());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function httpErrorStatuses(): iterable
    {
        foreach ([302, 304, 404, 429, 500, 502, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function testSnippetIsCutOnCharacterBoundary(): void
    {
        $body = '{"detail":"' . str_repeat('Ж', 300) . '"}';
        $transport = (new FakeHttpTransport())->willReturn(401, $body);

        try {
            (new ApiClient($transport))->requestJson(HttpRequest::get('https://h/'));
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame(1, preg_match('//u', $e->responseSnippet()), 'фрагмент должен быть валидным UTF-8');
            self::assertLessThanOrEqual(500, strlen($e->responseSnippet()));
            self::assertGreaterThanOrEqual(498, strlen($e->responseSnippet()));
        }
    }

    public function testSnippetOfBinaryBodyIsCutByBytes(): void
    {
        $body = str_repeat("\xff", 600);
        self::assertSame(600, strlen($body));
        $transport = (new FakeHttpTransport())->willReturn(500, $body);

        try {
            (new ApiClient($transport))->requestJson(HttpRequest::get('https://h/'));
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame(500, strlen($e->responseSnippet()));
        }
    }

    #[DataProvider('brokenBodies')]
    public function testBrokenJson(string $body): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, $body);

        try {
            (new ApiClient($transport))->requestJson(self::requestWithToken());
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertNull($e->getPrevious());
            self::assertNoFrame($e, 'json_decode');
            self::assertStringContainsString(strlen($body) . ' байт', $e->getMessage());
            json_decode($body);
            self::assertNotSame(JSON_ERROR_NONE, json_last_error());
            self::assertStringContainsString(json_last_error_msg(), $e->getMessage(), 'причина — в сообщении');
            self::assertStringContainsString('GET https://api.gdeslon.ru/api/search.xml?q=x&_gs_at=***', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenBodies(): iterable
    {
        yield 'пустое тело' => [''];
        yield 'HTML' => ['<!DOCTYPE html><html><body>Ошибка</body></html>'];
        yield 'обрезанный JSON' => ['{"1":{"_id":1,"name":"Подар'];
        yield 'невалидный UTF-8' => ["{\"name\":\"\xff\xfe\"}"];
        yield 'глубже 512 уровней' => [str_repeat('[', 600) . str_repeat(']', 600)];
    }

    public function testBrokenJsonBodyIsHiddenFromTrace(): void
    {
        self::requireSensitiveParameter();
        $body = '[{"sub_id":"sub-ПЕТРОВ-42","merchant_order_id":"GS-ЗАКАЗ-ИВАНОВ-777"';
        $client = new ApiClient((new FakeHttpTransport())->willReturn(200, $body));

        $e = self::catchWithTraceArgs(static fn () => $client->requestJson(self::requestWithToken()), UnexpectedResponseException::class);

        self::assertTraceHidesSecrets($e, 'sub-ПЕТРОВ-42', 'GS-ЗАКАЗ-ИВАНОВ-777');
    }

    public function testScalarJsonIsStillDecoded(): void
    {
        $client = new ApiClient(new FakeHttpTransport());

        self::assertNull($client->decodeJson(HttpRequest::get('https://h/'), new HttpResponse(200, 'null', [])));
        self::assertSame(0, $client->decodeJson(HttpRequest::get('https://h/'), new HttpResponse(200, '0', [])));
    }

    public function testTransportExceptionsPassThroughUnchanged(): void
    {
        $timeout = new TimeoutException('таймаут', 'GET', 'https://h/', 28);
        $refused = new TransportException('отказ', 'GET', 'https://h/', 7);
        $transport = (new FakeHttpTransport())->willThrow($timeout)->willThrow($refused);
        $client = new ApiClient($transport);

        foreach ([$timeout, $refused] as $expected) {
            try {
                $client->requestJson(HttpRequest::get('https://h/'));
                self::fail('Ожидалось исключение');
            } catch (TransportException $e) {
                self::assertSame($expected, $e);
            }
        }
    }

    public function testSecretValuesEchoedInBodyAreMasked(): void
    {
        $body = "There have been validation errors: [ { param: '_gs_at',     msg: 'Invalid value',     value: 'secret-token' } ]";
        $transport = (new FakeHttpTransport())->willReturn(404, $body);

        try {
            (new ApiClient($transport))->requestJson(self::requestWithToken());
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->responseSnippet());
            self::assertStringContainsString("value: '***'", $e->responseSnippet());
        }
    }

    public function testFetchReturnsSuccessAndNotModified(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '{}')->willReturn(304, '');
        $client = new ApiClient($transport);

        self::assertSame(200, $client->fetch(HttpRequest::get('https://h/'))->statusCode());
        self::assertSame(304, $client->fetch(HttpRequest::get('https://h/'))->statusCode());
    }

    public function testFetchStillRejectsErrors(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(404, 'нет')->willReturn(401, 'нет');
        $client = new ApiClient($transport);

        try {
            $client->fetch(HttpRequest::get('https://h/'));
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame(404, $e->statusCode());
        }

        $this->expectException(AuthenticationException::class);
        $client->fetch(HttpRequest::get('https://h/'));
    }

    public function testDecodeJson(): void
    {
        $client = new ApiClient(new FakeHttpTransport());
        $request = HttpRequest::get('https://h/');

        self::assertSame(['a' => 1], $client->decodeJson($request, new HttpResponse(200, '{"a":1}', [])));

        $this->expectException(UnexpectedResponseException::class);
        $client->decodeJson($request, new HttpResponse(200, '{"a":', []));
    }

    private static function requestWithToken(): HttpRequest
    {
        return HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['q' => 'x', '_gs_at' => self::SECRET]);
    }

    public function testBasicCredentialsEchoedInBodyAreMasked(): void
    {
        $basic = base64_encode('1234:test-api-key');
        $transport = (new FakeHttpTransport())->willReturn(401, '{"detail":"bad credentials test-api-key / ' . $basic . '"}');
        $request = HttpRequest::post('https://gdeslon.ru/api/orders/', '{}', ['Authorization' => 'Basic ' . $basic]);

        try {
            (new ApiClient($transport))->requestJson($request);
            self::fail('Ожидалось исключение');
        } catch (AuthenticationException $e) {
            foreach (['test-api-key', $basic] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
                self::assertStringNotContainsString($secret, $e->url());
                self::assertStringNotContainsString($secret, $e->responseSnippet());
            }
        }
    }

    public function testBigIntegersStayStrings(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '{"id": 123456789012345678901, "small": 7}');

        self::assertSame(['id' => '123456789012345678901', 'small' => 7], (new ApiClient($transport))->requestJson(HttpRequest::get('https://h/')));
    }

    public function testRequestAllowingReturnsListedStatuses(): void
    {
        $transport = (new FakeHttpTransport())
            ->willReturn(400, '{"errors":{"start_date":["x"]}}')
            ->willReturn(404, '{"errors":{"detail":"Не найдено."}}')
            ->willReturn(201, '{}');
        $client = new ApiClient($transport);
        $request = HttpRequest::get('https://h/');

        self::assertSame(400, $client->requestAllowing($request, 400, 404)->statusCode());
        self::assertSame(404, $client->requestAllowing($request, 400, 404)->statusCode());
        self::assertSame(201, $client->requestAllowing($request, 400, 404)->statusCode());

        foreach ([401 => AuthenticationException::class, 403 => AuthenticationException::class, 500 => HttpException::class, 304 => HttpException::class] as $status => $class) {
            try {
                (new ApiClient((new FakeHttpTransport())->willReturn($status, 'x')))->requestAllowing($request, 400, 401, 403, 404);
                self::fail('Ожидалось исключение: ' . $status);
            } catch (HttpException $e) {
                self::assertInstanceOf($class, $e);
                self::assertSame($status, $e->statusCode());
            }
        }
    }
}
