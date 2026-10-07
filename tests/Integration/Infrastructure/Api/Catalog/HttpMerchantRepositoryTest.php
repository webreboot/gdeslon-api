<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpMerchantRepository;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class HttpMerchantRepositoryTest extends TestCase
{
    private const URL = 'https://www.gdeslon.ru/api/users/shops.xml';
    private const TOKEN = 'secret-token';

    public function testLoadsMerchantsWithToken(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops.xml'));

        $list = self::repository($transport, self::TOKEN)->all();

        self::assertCount(9, $list);
        self::assertCount(1, $transport->requests());
        $request = $transport->lastRequest();
        self::assertSame('GET', $request->method());
        self::assertSame(self::URL . '?api_token=' . self::TOKEN, $request->uri());
        self::assertNull($request->header('Range'));
        self::assertNull($request->header('Authorization'));
    }

    public function testWithoutTokenLoadsPublicCatalog(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops-public.xml'));

        $list = self::repository($transport)->all();

        self::assertSame(self::URL, $transport->lastRequest()->uri());
        self::assertCount(2, $list);
        self::assertNull($list->get(105263)->affiliateLink());
    }

    public function testRejectedTokenIsReported(): void
    {
        // API отвечает на неверный токен 200 и публичным каталогом без партнёрских ссылок
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops-public.xml'));

        try {
            self::repository($transport, self::TOKEN)->all();
            self::fail('Ожидалось исключение');
        } catch (AuthenticationException $e) {
            self::assertSame(200, $e->statusCode());
            self::assertStringContainsString('токен XML API не принят', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->url());
            self::assertStringContainsString('api_token=***', $e->url());
        }
    }

    public function testEmptyCatalogWithTokenIsNotAnError(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '<?xml version="1.0" encoding="UTF-8"?><shops/>');

        self::assertCount(0, self::repository($transport, self::TOKEN)->all());
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('httpErrors')]
    public function testHttpErrors(int $status, string $expected): void
    {
        $transport = (new FakeHttpTransport())->willReturn($status, '<html>Cannot GET /api/users/shops.xml?api_token=' . self::TOKEN . '</html>');

        try {
            self::repository($transport, self::TOKEN)->all();
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertStringNotContainsString(self::TOKEN, $e->url());
            self::assertStringNotContainsString(self::TOKEN, $e->responseSnippet());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function httpErrors(): iterable
    {
        yield '403' => [403, AuthenticationException::class];
        yield '404' => [404, HttpException::class];
        yield '500' => [500, HttpException::class];
        yield '502' => [502, HttpException::class];
    }

    public function testTransportErrorsAndBrokenBody(): void
    {
        $timeout = new TimeoutException('таймаут', 'GET', self::URL . '?api_token=***', 28);
        $transport = (new FakeHttpTransport())->willThrow($timeout)->willReturn(200, '<!DOCTYPE html><html>сервис недоступен</html>');
        $repository = self::repository($transport, self::TOKEN);

        try {
            $repository->all();
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e);
        }

        try {
            $repository->all();
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testNotModifiedWithoutCacheIsUnexpected(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(304, '');

        $this->expectException(UnexpectedResponseException::class);

        self::repository($transport, self::TOKEN)->all();
    }

    private static function repository(FakeHttpTransport $transport, ?string $token = null): HttpMerchantRepository
    {
        return new HttpMerchantRepository(new ApiClient($transport), $token);
    }
}
