<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\HeaderParser;

final class HeaderParserTest extends TestCase
{
    public function testParsesRealCategoriesResponseHeaders(): void
    {
        $parser = self::parse([
            "HTTP/1.1 200 OK\r\n",
            "Server: nginx/1.24.0\r\n",
            "Content-Type: application/json\r\n",
            "Content-Length: 159451\r\n",
            "Last-Modified: Fri, 01 Nov 2024 10:37:41 GMT\r\n",
            "ETag: \"6724af75-26edb\"\r\n",
            "Content-Type: application/json; charset=utf-8\r\n",
            "Accept-Ranges: bytes\r\n",
            "\r\n",
        ]);

        self::assertSame([
            'server' => ['nginx/1.24.0'],
            'content-type' => ['application/json', 'application/json; charset=utf-8'],
            'content-length' => ['159451'],
            'last-modified' => ['Fri, 01 Nov 2024 10:37:41 GMT'],
            'etag' => ['"6724af75-26edb"'],
            'accept-ranges' => ['bytes'],
        ], $parser->headers());
    }

    public function testNewStatusLineResetsHeadersOfInterimResponse(): void
    {
        $parser = self::parse([
            "HTTP/1.1 100 Continue\r\n",
            "X-Interim: 1\r\n",
            "\r\n",
            "HTTP/1.1 200 OK\r\n",
            "X-Final: 2\r\n",
            "\r\n",
        ]);

        self::assertSame(['x-final' => ['2']], $parser->headers());
    }

    public function testIgnoresMalformedLinesAndTrimsValues(): void
    {
        $parser = self::parse([
            "HTTP/2 200\r\n",
            "garbage without colon\r\n",
            "   \r\n",
            "X-Spaces:    value with spaces   \r\n",
            ": no name\r\n",
        ]);

        self::assertSame(['x-spaces' => ['value with spaces']], $parser->headers());
    }

    /**
     * @param list<string> $lines
     */
    private static function parse(array $lines): HeaderParser
    {
        $parser = new HeaderParser();
        foreach ($lines as $line) {
            $parser->addLine($line);
        }

        return $parser;
    }
}
