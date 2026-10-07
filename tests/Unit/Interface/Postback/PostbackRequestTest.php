<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Postback;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Postback\InvalidPostbackException;
use Webreboot\GdeSlon\Interface\Postback\PostbackRequest;

final class PostbackRequestTest extends TestCase
{
    public function testHeadersAreCaseInsensitive(): void
    {
        $request = new PostbackRequest(
            'post',
            ['Content-Type' => 'application/json', 'X-Gdeslon-Secret' => ['a', 'b']],
            'a=1',
            '{"merchant_id":1}',
            ['x' => 'y'],
        );

        self::assertSame('POST', $request->method());
        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame(['a', 'b'], $request->headerValues('x-gdeslon-secret'));
        self::assertSame([], $request->headerValues('Authorization'));
        self::assertNull($request->header('Authorization'));
        self::assertSame('a=1', $request->query());
        self::assertSame('{"merchant_id":1}', $request->body());
        self::assertSame(['x' => 'y'], $request->form());
        self::assertNull((new PostbackRequest('GET'))->form());
    }

    public function testFromServer(): void
    {
        $input = self::stream('{"merchant_id":"1"}');

        $request = PostbackRequest::fromServer([
            'REQUEST_METHOD' => 'POST',
            'QUERY_STRING' => 'sub.id=1&a+b=2',
            'HTTP_X_GDESLON_SECRET' => 'test-secret-0123456789',
            'HTTP_USER_AGENT' => 'GdeSlon',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '19',
            'SERVER_NAME' => 'example.com',
        ], ['x' => 'y'], $input);

        self::assertSame('POST', $request->method());
        self::assertSame('test-secret-0123456789', $request->header('X-Gdeslon-Secret'));
        self::assertSame('GdeSlon', $request->header('User-Agent'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('19', $request->header('Content-Length'));
        self::assertNull($request->header('Server-Name'));
        self::assertSame('sub.id=1&a+b=2', $request->query(), 'сырая строка, а не $_GET');
        self::assertSame('{"merchant_id":"1"}', $request->body());
        self::assertSame(['x' => 'y'], $request->form());

        $get = PostbackRequest::fromServer([], [], self::stream(''));
        self::assertSame('GET', $get->method());
        self::assertSame('', $get->query());
        self::assertNull($get->form());
    }

    public function testBodyLimit(): void
    {
        $input = self::stream(str_repeat('x', 101));
        try {
            PostbackRequest::fromServer(['REQUEST_METHOD' => 'POST'], [], $input, 100);
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(413, $e->responseStatus());
            self::assertSame(101, ftell($input), 'прочитано не больше max+1 байт');
        }

        $untouched = self::stream(str_repeat('x', 10));
        try {
            PostbackRequest::fromServer(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '5000'], [], $untouched, 100);
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(413, $e->responseStatus());
            self::assertSame(0, ftell($untouched), 'по Content-Length тело не читается');
        }

        self::assertSame(100, strlen(PostbackRequest::fromServer(['REQUEST_METHOD' => 'POST'], [], self::stream(str_repeat('x', 100)), 100)->body()));
    }

    public function testSecretsAreHiddenFromDumpsAndTraces(): void
    {
        $request = new PostbackRequest('POST', ['X-Gdeslon-Secret' => 'test-secret-0123456789', 'Content-Type' => 'application/json'], 'a=1', '{}');

        ob_start();
        var_dump($request);
        $dump = (string) ob_get_clean() . print_r($request, true);
        self::assertStringNotContainsString('test-secret-0123456789', $dump);
        self::assertStringContainsString('application/json', $dump);

        if (PHP_VERSION_ID >= 80200) {
            // на 8.1 #[\SensitiveParameter] не действует — массив $server в трейсе не скрыть
            try {
                PostbackRequest::fromServer(['HTTP_X_GDESLON_SECRET' => 'test-secret-0123456789', 'CONTENT_LENGTH' => '999999'], [], null, 10);
                self::fail('Ожидалось исключение');
            } catch (InvalidPostbackException $e) {
                self::assertStringNotContainsString('test-secret-0123456789', var_export($e->getTrace(), true));
            }
        }
    }

    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
