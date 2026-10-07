<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Promo;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteriaRejectedException;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Promo\HttpCouponFeed;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class HttpCouponFeedTest extends TestCase
{
    private const URL = 'https://gdeslon.ru/api/coupons.xml';
    private const TOKEN = 'secret-token-0123';

    public function testTokenIsRequired(): void
    {
        $transport = new FakeHttpTransport();

        try {
            (new HttpCouponFeed(new ApiClient($transport)))->find(new CouponCriteria());
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('токен XML API', $e->getMessage());
        }
        self::assertSame([], $transport->requests());
    }

    public function testRequest(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons.xml'), ['Content-Type' => ['application/xml; charset=utf-8']]);
        $criteria = new CouponCriteria();

        $list = self::feed($transport)->find($criteria);

        self::assertCount(7, $list);
        self::assertSame($criteria, $list->criteria());
        $request = $transport->lastRequest();
        self::assertSame('GET', $request->method());
        self::assertSame(self::URL . '?api_token=' . self::TOKEN, $request->uri());
        self::assertNull($request->header('Authorization'));
        self::assertStringNotContainsString(self::TOKEN, $request->maskedUri());
    }

    public function testCriteriaAreRepeatedParameters(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons-empty.xml'));

        self::feed($transport)->find(new CouponCriteria(merchants: [99157, 118031], kinds: [1, 14]));

        self::assertSame(self::URL . '?api_token=' . self::TOKEN . '&merchant_id=99157&merchant_id=118031&kind=1&kind=14', $transport->lastRequest()->uri());
    }

    public function testRejectedToken(): void
    {
        foreach (['error-401-no-token.xml', 'error-401-bad-token.xml'] as $fixture) {
            try {
                self::feed((new FakeHttpTransport())->willReturn(401, Fixtures::read('coupons/' . $fixture)))->find(new CouponCriteria());
                self::fail('Ожидалось исключение');
            } catch (AuthenticationException $e) {
                self::assertSame(401, $e->statusCode());
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage() . $e->url() . $e->responseSnippet());
            }
        }
    }

    public function testRejectedCriteria(): void
    {
        try {
            self::feed((new FakeHttpTransport())->willReturn(400, Fixtures::read('coupons/error-400-merchant.xml')))->find(CouponCriteria::forMerchant(23707));
            self::fail('Ожидалось исключение');
        } catch (CouponCriteriaRejectedException $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame(['merchant_id' => ['Выберите корректный вариант. 23707 нет среди допустимых значений.']], $e->errors());
            self::assertStringContainsString('merchant_id', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage() . $e->url());
        }

        try {
            self::feed((new FakeHttpTransport())->willReturn(400, '<html>Bad Request</html>'))->find(new CouponCriteria());
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertNotInstanceOf(CouponCriteriaRejectedException::class, $e);
            self::assertSame(400, $e->statusCode());
        }
    }

    public function testOtherErrors(): void
    {
        foreach ([500, 404] as $status) {
            try {
                self::feed((new FakeHttpTransport())->willReturn($status, '<html>error</html>'))->find(new CouponCriteria());
                self::fail('Ожидалось исключение');
            } catch (HttpException $e) {
                self::assertSame($status, $e->statusCode());
            }
        }

        $refused = new TransportException('отказ', 'GET', self::URL, 7);
        try {
            self::feed((new FakeHttpTransport())->willThrow($refused))->find(new CouponCriteria());
            self::fail('Ожидалось исключение');
        } catch (TransportException $e) {
            self::assertSame($refused, $e);
        }
    }

    public function testBrokenBodyWithTokenInLinks(): void
    {
        $body = substr(str_replace('0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e', self::TOKEN, Fixtures::read('coupons/coupons.xml')), 0, 5000);

        try {
            self::feed((new FakeHttpTransport())->willReturn(200, $body))->find(new CouponCriteria());
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
        }
    }

    public function testTokenIsHiddenFromDumpsAndSerialization(): void
    {
        $feed = self::feed(new FakeHttpTransport());

        self::assertStringNotContainsString(self::TOKEN, print_r($feed, true));

        $this->expectException(InvalidArgumentException::class);
        serialize($feed);
    }

    private static function feed(FakeHttpTransport $transport): HttpCouponFeed
    {
        return new HttpCouponFeed(new ApiClient($transport), self::TOKEN);
    }

    public function testBrokenBodyDoesNotLeakTokenThroughTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $body = (string) preg_replace('~<merchant-id>\d+</merchant-id>~', '<merchant-id>x</merchant-id>', str_replace('0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e', self::TOKEN, Fixtures::read('coupons/coupons.xml')));
        try {
            self::feed((new FakeHttpTransport())->willReturn(200, $body))->find(new CouponCriteria());
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('ни одна запись не разобрана', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, print_r($e, true));
            self::assertStringNotContainsString(self::TOKEN, var_export(array_column($e->getTrace(), 'args'), true));
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }
}
