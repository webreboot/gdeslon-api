<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;

final class HttpRequestTest extends TestCase
{
    public function testGetWithoutQuery(): void
    {
        $request = HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json');

        self::assertSame('GET', $request->method());
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $request->url());
        self::assertSame([], $request->query());
        self::assertSame([], $request->headers());
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $request->uri());
    }

    public function testQueryIsEncodedPerRfc3986(): void
    {
        $request = HttpRequest::get(
            'https://api.gdeslon.ru/api/search.xml',
            ['q' => 'платье красное', 'l' => 2],
            ['Accept' => 'application/xml'],
        );

        self::assertSame(
            'https://api.gdeslon.ru/api/search.xml?q=%D0%BF%D0%BB%D0%B0%D1%82%D1%8C%D0%B5%20%D0%BA%D1%80%D0%B0%D1%81%D0%BD%D0%BE%D0%B5&l=2',
            $request->uri(),
        );
        self::assertSame(['Accept' => 'application/xml'], $request->headers());
    }

    public function testQueryIsAppendedToExistingQueryString(): void
    {
        self::assertSame('https://h/p?a=1&b=2', HttpRequest::get('https://h/p?a=1', ['b' => 2])->uri());
    }

    public function testBooleanQueryValuesAreSentAsNumbers(): void
    {
        self::assertSame('https://h/p?on=1&off=0', HttpRequest::get('https://h/p', ['on' => true, 'off' => false])->uri());
    }

    public function testMaskedUriHidesTokens(): void
    {
        $request = HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['q' => 'x', '_gs_at' => 'secret-token']);

        self::assertStringNotContainsString('secret-token', $request->maskedUri());
        self::assertSame('https://api.gdeslon.ru/api/search.xml?q=x&_gs_at=***', $request->maskedUri());
    }

    public function testMethodIsUppercased(): void
    {
        self::assertSame('POST', (new HttpRequest('post', 'https://h/'))->method());
    }

    public function testWithHeadersReturnsNewRequest(): void
    {
        $original = HttpRequest::get('https://h/p', ['_gs_at' => 't'], ['Accept' => 'x'])->withTimeout(5.0);

        $changed = $original->withHeaders(['Range' => 'bytes=0-9']);

        self::assertNotSame($original, $changed);
        self::assertSame(['Accept' => 'x'], $original->headers());
        self::assertSame(['Range' => 'bytes=0-9'], $changed->headers());
        self::assertSame('GET', $changed->method());
        self::assertSame('https://h/p', $changed->url());
        self::assertSame(['_gs_at' => 't'], $changed->query());
        self::assertSame(5.0, $changed->timeout());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = HttpRequest::get('https://h/', [], ['Range' => 'bytes=0-9']);

        self::assertSame('bytes=0-9', $request->header('RANGE'));
        self::assertNull($request->header('If-Match'));
    }

    public function testTimeout(): void
    {
        $request = HttpRequest::get('https://h/');

        self::assertNull($request->timeout());
        self::assertSame(5.0, $request->withTimeout(5.0)->timeout());
        self::assertNull($request->timeout(), 'исходный запрос не меняется');
    }

    #[DataProvider('invalidTimeouts')]
    public function testRejectsNonPositiveTimeout(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpRequest::get('https://h/')->withTimeout($timeout);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'ноль' => [0.0];
        yield 'отрицательный' => [-1.0];
    }

    public function testPostWithBody(): void
    {
        $request = HttpRequest::post('https://gdeslon.ru/api/orders/', '{"a":1}', ['Content-Type' => 'application/json']);

        self::assertSame('POST', $request->method());
        self::assertSame('{"a":1}', $request->body());
        self::assertSame('application/json', $request->header('content-type'));
        self::assertNull(HttpRequest::get('https://h/')->body());
    }

    public function testCopiesKeepBody(): void
    {
        $request = HttpRequest::post('https://h/', 'тело')->withHeaders(['X' => '1'])->withTimeout(2.5);

        self::assertSame('тело', $request->body());
        self::assertSame('POST', $request->method());
        self::assertSame('1', $request->header('X'));
        self::assertSame(2.5, $request->timeout());
    }

    public function testDumpHidesAuthorization(): void
    {
        $request = HttpRequest::post('https://h/', '{}', ['Authorization' => 'Basic MTIzNDpzZWNyZXQta2V5', 'Accept' => 'application/json']);

        ob_start();
        var_dump($request);
        $dump = (string) ob_get_clean() . print_r($request, true);

        self::assertStringNotContainsString('MTIzNDpzZWNyZXQta2V5', $dump);
        self::assertStringContainsString('application/json', $dump);
    }

    public function testDumpHidesLargeAndMultipartBodies(): void
    {
        $multipart = HttpRequest::post('https://h/', "--XyZ\r\nSECRET-RECEIPT\r\n--XyZ--\r\n", ['Content-Type' => 'multipart/form-data; boundary=XyZ']);
        $large = HttpRequest::post('https://h/', str_repeat('LARGE', 300), ['Content-Type' => 'application/json']);

        foreach ([$multipart, $large] as $request) {
            ob_start();
            var_dump($request);
            $dump = (string) ob_get_clean() . print_r($request, true);
            self::assertStringNotContainsString('SECRET-RECEIPT', $dump);
            self::assertStringNotContainsString('LARGELARGE', $dump);
            self::assertStringContainsString(strlen((string) $request->body()) . ' байт', $dump);
        }

        self::assertStringContainsString('{"a":1}', print_r(HttpRequest::post('https://h/', '{"a":1}'), true), 'небольшое тело — как раньше');
    }

    public function testRepeatedQueryParameters(): void
    {
        $request = HttpRequest::get('https://gdeslon.ru/api/coupons.xml', [
            'api_token' => 'secret-token',
            'merchant_id' => [99157, 118031],
            'kind' => [],
            'q' => 'a b',
        ]);

        self::assertSame('https://gdeslon.ru/api/coupons.xml?api_token=secret-token&merchant_id=99157&merchant_id=118031&q=a%20b', $request->uri());
        self::assertSame('https://gdeslon.ru/api/coupons.xml?api_token=***&merchant_id=99157&merchant_id=118031&q=a%20b', $request->maskedUri());
        self::assertSame([99157, 118031], $request->query()['merchant_id']);
        self::assertStringNotContainsString('secret-token', print_r($request, true));
        self::assertSame('https://h/?a=1&b=0&c=x', HttpRequest::get('https://h/', ['a' => true, 'b' => false, 'c' => 'x'])->uri(), 'скаляры как раньше');
    }
}
