<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Postback;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Postback\InvalidPostbackException;
use Webreboot\GdeSlon\Interface\Postback\PostbackRequest;

final class PostbackRequestPsr7Test extends TestCase
{
    public function testFromPsr7(): void
    {
        $body = Stream::create('{"merchant_id":"2573","state":"3"}');
        $body->getContents();
        $psr = (new ServerRequest('post', 'https://example.com/postback?sub.id=1&a+b=2', [
            'X-Gdeslon-Secret' => 'test-secret-0123456789',
            'Content-Type' => 'application/json',
        ], $body))->withParsedBody(['x' => 'y']);

        $request = PostbackRequest::fromPsr7($psr);

        self::assertSame('POST', $request->method());
        self::assertSame('test-secret-0123456789', $request->header('x-gdeslon-secret'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('sub.id=1&a+b=2', $request->query());
        self::assertSame('{"merchant_id":"2573","state":"3"}', $request->body());
        self::assertSame(['x' => 'y'], $request->form());
    }

    public function testBodyLimit(): void
    {
        $this->expectException(InvalidPostbackException::class);
        $this->expectExceptionMessage('100');

        PostbackRequest::fromPsr7(new ServerRequest('POST', 'https://example.com/', [], str_repeat('x', 101)), 100);
    }

    public function testContentLengthLimit(): void
    {
        try {
            PostbackRequest::fromPsr7(new ServerRequest('POST', 'https://example.com/', ['Content-Length' => '5000'], 'x'), 100);
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(413, $e->responseStatus());
        }
    }
}
