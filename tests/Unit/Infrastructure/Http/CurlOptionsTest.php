<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\CurlOptions;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;

final class CurlOptionsTest extends TestCase
{
    public function testSecureDefaults(): void
    {
        $options = self::options(HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json'));

        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertTrue($options[CURLOPT_RETURNTRANSFER]);
        self::assertTrue($options[CURLOPT_NOSIGNAL]);
        self::assertSame('', $options[CURLOPT_ACCEPT_ENCODING], 'пустая строка — libcurl предлагает и распаковывает все свои кодировки');
    }

    public function testRequestLine(): void
    {
        $request = HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['q' => 'чайник', 'l' => 5]);

        $options = self::options($request);

        self::assertSame($request->uri(), $options[CURLOPT_URL]);
        self::assertSame('GET', $options[CURLOPT_CUSTOMREQUEST]);
    }

    public function testTimeoutsInMilliseconds(): void
    {
        $options = CurlOptions::for(HttpRequest::get('https://h/'), 0.5, 2.25, 'ua', self::noop());

        self::assertSame(500, $options[CURLOPT_TIMEOUT_MS]);
        self::assertSame(2250, $options[CURLOPT_CONNECTTIMEOUT_MS]);
    }

    public function testUserAgentAndHeaders(): void
    {
        $request = HttpRequest::get('https://h/', [], ['Accept' => 'application/json', 'X-Test' => 'да']);

        $options = CurlOptions::for($request, 30.0, 10.0, 'webreboot-gdeslon-api/test', self::noop());

        self::assertSame('webreboot-gdeslon-api/test', $options[CURLOPT_USERAGENT]);
        self::assertSame(['Accept: application/json', 'X-Test: да'], $options[CURLOPT_HTTPHEADER]);
    }

    public function testHeaderFunctionIsPassedThrough(): void
    {
        $headerFunction = self::noop();

        $options = CurlOptions::for(HttpRequest::get('https://h/'), 30.0, 10.0, 'ua', $headerFunction);

        self::assertSame($headerFunction, $options[CURLOPT_HEADERFUNCTION]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function options(HttpRequest $request): array
    {
        return CurlOptions::for($request, 30.0, 10.0, 'ua', self::noop());
    }

    /**
     * @return \Closure(\CurlHandle, string): int
     */
    private static function noop(): \Closure
    {
        return static fn (\CurlHandle $handle, string $line): int => strlen($line);
    }

    public function testRequestTimeoutCanOnlyShortenTransportTimeout(): void
    {
        $request = HttpRequest::get('https://h/');

        self::assertSame(5000, CurlOptions::for($request->withTimeout(5.0), 30.0, 10.0, 'ua', self::noop())[CURLOPT_TIMEOUT_MS]);
        self::assertSame(30000, CurlOptions::for($request->withTimeout(60.0), 30.0, 10.0, 'ua', self::noop())[CURLOPT_TIMEOUT_MS]);
        self::assertSame(30000, CurlOptions::for($request, 30.0, 10.0, 'ua', self::noop())[CURLOPT_TIMEOUT_MS]);
    }

    public function testBodyIsSentAsIs(): void
    {
        $post = self::options(HttpRequest::post('https://gdeslon.ru/api/orders/', '{"sub_id":"тест"}'));

        self::assertSame('POST', $post[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('{"sub_id":"тест"}', $post[CURLOPT_POSTFIELDS]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, self::options(HttpRequest::get('https://h/')));
    }
}
