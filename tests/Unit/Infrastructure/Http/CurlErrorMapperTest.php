<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\CurlErrorMapper;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;

final class CurlErrorMapperTest extends TestCase
{
    public function testTimeout(): void
    {
        $request = HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['_gs_at' => 'secret-token']);

        $e = CurlErrorMapper::toException(
            $request,
            28,
            'Operation timed out after 30001 milliseconds with 16087 out of 159451 bytes received',
        );

        self::assertInstanceOf(TimeoutException::class, $e);
        self::assertSame(28, $e->curlErrorCode());
        self::assertSame('GET', $e->method());
        self::assertSame('https://api.gdeslon.ru/api/search.xml?_gs_at=***', $e->url());
        self::assertStringNotContainsString('secret-token', $e->getMessage());
        self::assertStringContainsString('GET https://api.gdeslon.ru/api/search.xml?_gs_at=***', $e->getMessage());
        self::assertStringContainsString('16087 out of 159451 bytes', $e->getMessage());
    }

    #[DataProvider('transportErrors')]
    public function testOtherCurlErrorsAreTransportErrors(int $code, string $error): void
    {
        $e = CurlErrorMapper::toException(HttpRequest::get('https://api.gdeslon.ru/gdeslon-categories.json'), $code, $error);

        self::assertNotInstanceOf(TimeoutException::class, $e);
        self::assertSame($code, $e->curlErrorCode());
        self::assertStringContainsString($error, $e->getMessage());
        self::assertStringContainsString('cURL ' . $code, $e->getMessage());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function transportErrors(): iterable
    {
        yield 'DNS' => [6, 'Could not resolve host: api.gdeslon.ru'];
        yield 'отказ соединения' => [7, 'Failed to connect to api.gdeslon.ru port 443'];
        yield 'обрыв тела' => [18, 'transfer closed with 990 bytes remaining to read'];
        yield 'TLS' => [35, 'OpenSSL SSL_connect: SSL_ERROR_SYSCALL'];
        yield 'сертификат' => [60, 'SSL certificate problem: unable to get local issuer certificate'];
    }

    public function testErrorTextIsMaskedToo(): void
    {
        $e = CurlErrorMapper::toException(
            HttpRequest::get('https://h/p'),
            3,
            'URL rejected: Malformed input to a URL function https://h/p?api_token=secret-token',
        );

        self::assertInstanceOf(TransportException::class, $e);
        self::assertStringNotContainsString('secret-token', $e->getMessage());
    }
}
