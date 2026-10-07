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
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;

final class ApiClientTest extends TestCase
{
    private const SECRET = 'secret-token';

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
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
            self::assertStringContainsString(strlen($body) . ' байт', $e->getMessage());
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
