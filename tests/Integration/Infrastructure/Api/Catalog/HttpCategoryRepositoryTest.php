<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Catalog;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpCategoryRepository;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class HttpCategoryRepositoryTest extends TestCase
{
    private const URL = 'https://api.gdeslon.ru/gdeslon-categories.json';

    public function testLoadsAllCategoriesWithOnePublicRequest(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('categories/categories.json'), [
            'Content-Type' => ['application/json', 'application/json; charset=utf-8'],
        ]);

        $tree = (new HttpCategoryRepository(new ApiClient($transport)))->all();

        self::assertCount(23, $tree);
        self::assertSame('Женская одежда', $tree->get(1114)->name());

        self::assertCount(1, $transport->requests());
        $request = $transport->lastRequest();
        self::assertSame('GET', $request->method());
        self::assertSame(self::URL, $request->uri());
        self::assertArrayNotHasKey('Authorization', $request->headers());
    }

    public function testTruncatedResponse(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, substr(Fixtures::read('categories/categories.json'), 0, 1500));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage(self::URL);

        (new HttpCategoryRepository(new ApiClient($transport)))->all();
    }

    public function testHttpError(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(500, 'Internal Server Error');

        $this->expectException(HttpException::class);

        (new HttpCategoryRepository(new ApiClient($transport)))->all();
    }

    public function testTimeoutPassesThrough(): void
    {
        $timeout = new TimeoutException('таймаут', 'GET', self::URL, 28);
        $transport = (new FakeHttpTransport())->willThrow($timeout);

        try {
            (new HttpCategoryRepository(new ApiClient($transport)))->all();
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e);
        }
    }

    public function testCustomUrl(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '{}');

        (new HttpCategoryRepository(new ApiClient($transport), 'https://mirror.example/categories.json'))->all();

        self::assertSame('https://mirror.example/categories.json', $transport->lastRequest()->uri());
    }
}
