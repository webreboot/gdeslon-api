<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Sales;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Sales\HttpOrderRepository;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;

final class HttpOrderRepositoryTest extends TestCase
{
    private const USER = '1234';
    private const KEY = 'test-api-key';

    public function testDefaultCriteria(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '[]', ['Content-Type' => ['application/json']]);

        $list = self::repository($transport, FrozenClock::at('2026-10-07T10:00:00Z'))->find(new OrderCriteria());

        self::assertTrue($list->isEmpty());
        self::assertCount(1, $transport->requests());
        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method());
        self::assertSame('https://gdeslon.ru/api/orders/', $request->uri());
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('application/json', $request->header('Accept'));
        self::assertSame('Basic ' . base64_encode(self::USER . ':' . self::KEY), $request->header('Authorization'));
        self::assertSame('{"created_at":{"date":"2026-10-07","period":30}}', $request->body());
    }

    public function testTodayIsMoscowDate(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '[]');

        self::repository($transport, FrozenClock::at('2026-10-06T22:30:00Z'))->find(new OrderCriteria());

        self::assertSame('{"created_at":{"date":"2026-10-07","period":30}}', $transport->lastRequest()->body());
    }

    public function testAllFilters(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '[]');
        $criteria = new OrderCriteria(
            dateField: OrderDateField::Confirmed,
            until: '2026-09-30',
            days: 30,
            merchant: 2573,
            states: [OrderState::Confirmed, OrderState::Paid],
            type: OrderType::Product,
            subId: '123',
        );

        self::repository($transport)->find($criteria);

        self::assertSame(
            '{"confirmed_at":{"date":"2026-09-30","period":30},"merchant_id":2573,"state":[3,4],"type":0,"sub_id":"123"}',
            $transport->lastRequest()->body(),
        );
    }

    public function testCyrillicSubIdIsSentAsUtf8(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '[]');

        self::repository($transport)->find(new OrderCriteria(until: '2026-10-07', type: OrderType::Lead, subId: 'тест/1'));

        self::assertSame('{"created_at":{"date":"2026-10-07","period":30},"type":1,"sub_id":"тест/1"}', $transport->lastRequest()->body());
    }

    public function testOrdersAreMapped(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('orders/orders-synthetic.json'), ['Content-Type' => ['application/json']]);
        $criteria = new OrderCriteria(days: 7);

        $list = self::repository($transport)->find($criteria);

        self::assertCount(4, $list);
        self::assertSame($criteria, $list->criteria());
        self::assertSame('150.00 RUB', (string) $list->find('81234567')?->reward());
    }

    #[DataProvider('rejectedCredentials')]
    public function testRejectedCredentials(string $body): void
    {
        $transport = (new FakeHttpTransport())->willReturn(401, $body, ['WWW-Authenticate' => ['Basic realm="api"']]);

        try {
            self::repository($transport)->find(new OrderCriteria());
            self::fail('Ожидалось исключение');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->statusCode());
            self::assertNoSecrets($e);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedCredentials(): iterable
    {
        yield 'нет заголовка' => ['{"detail":"Некорректные учетные данные."}'];
        yield 'неверные ключи' => ['{"detail":"Недопустимые имя пользователя или пароль."}'];
        yield 'эхо ключа' => ['{"detail":"bad ' . self::KEY . ' ' . base64_encode(self::USER . ':' . self::KEY) . '"}'];
    }

    #[DataProvider('httpErrors')]
    public function testHttpErrors(int $status, string $body, string $snippet): void
    {
        $transport = (new FakeHttpTransport())->willReturn($status, $body);

        try {
            self::repository($transport)->find(new OrderCriteria());
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertNotInstanceOf(AuthenticationException::class, $e);
            self::assertSame($status, $e->statusCode());
            self::assertStringContainsString($snippet, $e->responseSnippet());
            self::assertNoSecrets($e);
        }
        self::assertCount(1, $transport->requests(), 'запрос не повторяется');
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function httpErrors(): iterable
    {
        yield 'битый JSON' => [400, '{"detail":"JSON parse error - Expecting value: line 1 column 1 (char 0)"}', 'JSON parse error'];
        yield 'чужой Content-Type' => [415, '{"detail":"Неподдерживаемый тип данных \"text/plain\" в запросе."}', 'Неподдерживаемый тип'];
        yield 'неверный фильтр' => [500, Fixtures::read('orders/error-500.html'), '<!DOCTYPE html>'];
        yield 'адрес без слэша' => [301, '', ''];
        yield 'не изменён без тела' => [304, '', ''];
        yield 'не изменён с []' => [304, '[]', '[]'];
    }

    public function testObjectResponseIsBrokenDocument(): void
    {
        foreach (['{}', ' {"results": []}'] as $body) {
            $transport = (new FakeHttpTransport())->willReturn(200, $body);
            try {
                self::repository($transport)->find(new OrderCriteria());
                self::fail('Ожидалось исключение');
            } catch (UnexpectedResponseException $e) {
                self::assertStringContainsString('объект', $e->getMessage());
            }
        }
    }

    public function testHtmlInsteadOfJson(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, '<!DOCTYPE html><html>техработы</html>');

        $this->expectException(UnexpectedResponseException::class);

        self::repository($transport)->find(new OrderCriteria());
    }

    public function testTransportErrorsPassThrough(): void
    {
        foreach ([new TimeoutException('таймаут', 'POST', 'https://gdeslon.ru/api/orders/', 28), new TransportException('отказ', 'POST', 'https://gdeslon.ru/api/orders/', 7)] as $error) {
            $transport = (new FakeHttpTransport())->willThrow($error);
            try {
                self::repository($transport)->find(new OrderCriteria());
                self::fail('Ожидалось исключение');
            } catch (TransportException $e) {
                self::assertSame($error, $e);
            }
        }
    }

    public function testCredentialsAreRequiredBeforeRequest(): void
    {
        $transport = new FakeHttpTransport();

        try {
            (new HttpOrderRepository(new ApiClient($transport)))->find(new OrderCriteria());
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('userId', $e->getMessage());
        }
        self::assertSame([], $transport->requests());
    }

    public function testKeyIsHiddenFromDumpsAndSerialization(): void
    {
        $repository = self::repository(new FakeHttpTransport());

        ob_start();
        var_dump($repository);
        $dump = (string) ob_get_clean() . print_r($repository, true);
        self::assertStringNotContainsString(self::KEY, $dump);

        try {
            serialize($repository);
            self::fail('Ожидалось исключение');
        } catch (GdeSlonException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
        self::assertStringContainsString('HttpOrderRepository', serialize(new HttpOrderRepository(new ApiClient(new FakeHttpTransport()))), 'без ключей сериализуется');
    }

    private static function repository(FakeHttpTransport $transport, ?FrozenClock $clock = null): HttpOrderRepository
    {
        return new HttpOrderRepository(new ApiClient($transport), self::USER, self::KEY, $clock ?? FrozenClock::at('2026-10-07T10:00:00Z'));
    }

    private static function assertNoSecrets(HttpException $e): void
    {
        foreach ([self::KEY, base64_encode(self::USER . ':' . self::KEY)] as $secret) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, $e->url());
            self::assertStringNotContainsString($secret, $e->responseSnippet());
        }
    }
}
