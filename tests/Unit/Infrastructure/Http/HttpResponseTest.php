<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;

final class HttpResponseTest extends TestCase
{
    public function testHeadersAreCaseInsensitiveAndKeepRepeats(): void
    {
        $response = new HttpResponse(200, '{}', [
            'Content-Type' => ['application/json', 'application/json; charset=utf-8'],
            'ETag' => ['"6724af75-26edb"'],
        ]);

        self::assertSame(200, $response->statusCode());
        self::assertSame('{}', $response->body());
        self::assertSame('application/json', $response->header('CONTENT-TYPE'));
        self::assertSame(['application/json', 'application/json; charset=utf-8'], $response->headerValues('content-type'));
        self::assertSame('"6724af75-26edb"', $response->header('etag'));
        self::assertSame(['content-type', 'etag'], array_keys($response->headers()));
    }

    public function testMissingHeader(): void
    {
        $response = new HttpResponse(200, '', []);

        self::assertNull($response->header('x-missing'));
        self::assertSame([], $response->headerValues('x-missing'));
    }

    #[DataProvider('statuses')]
    public function testIsSuccessful(int $status, bool $expected): void
    {
        self::assertSame($expected, (new HttpResponse($status, '', []))->isSuccessful());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield '200' => [200, true];
        yield '204' => [204, true];
        yield '299' => [299, true];
        yield '199' => [199, false];
        yield '304' => [304, false];
        yield '404' => [404, false];
        yield '500' => [500, false];
    }
}
