<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Api\Claims;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Claims\HttpLostOrderClaims;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Testing\FrozenClock;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\PersonalDataMarkers;
use Webreboot\GdeSlon\Tests\Support\TraceSecrets;

final class HttpLostOrderClaimsTest extends TestCase
{
    use TraceSecrets;

    private const URL = 'https://gdeslon.ru/api/v1/lost-orders/';
    private const TOKEN = 'test-token';
    private const PDF = "%PDF-1.4\nчек";

    public function testFindWithoutCriteria(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('lost-orders/list-empty.json'), ['Content-Type' => ['application/json']]);

        $list = self::claims($transport)->find(new LostOrderCriteria());

        self::assertTrue($list->isEmpty());
        self::assertCount(1, $transport->requests());
        $request = $transport->lastRequest();
        self::assertSame('GET', $request->method());
        self::assertSame(self::URL, $request->uri());
        self::assertSame('Bearer ' . self::TOKEN, $request->header('Authorization'));
        self::assertSame('application/json', $request->header('Accept'));
    }

    public function testFindWithAllCriteria(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('lost-orders/claims-synthetic.json'));

        $list = self::claims($transport)->find(new LostOrderCriteria(
            merchant: 2573,
            from: '2026-07-01',
            until: '2026-10-07',
            claimState: LostOrderClaimState::InWork,
            orderStatus: LostOrderStatus::Waiting,
        ));

        self::assertSame(
            self::URL . '?merchant_id=2573&start_date=2026-07-01&end_date=2026-10-07&ticket_state=in_work&order_status=waiting',
            $transport->lastRequest()->uri(),
        );
        self::assertSame([5797], array_map(static fn ($c): int => $c->id()->value(), $list->all()), 'клиентский фильтр');
    }

    public function testListShapes(): void
    {
        self::assertTrue(self::claims((new FakeHttpTransport())->willReturn(200, Fixtures::read('lost-orders/list-paginated-empty.json')))->find(new LostOrderCriteria())->isEmpty());

        foreach (['{}', '<html>техработы</html>'] as $body) {
            try {
                self::claims((new FakeHttpTransport())->willReturn(200, $body))->find(new LostOrderCriteria());
                self::fail('Ожидалось исключение: ' . $body);
            } catch (UnexpectedResponseException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('validationErrors')]
    public function testFindValidationErrors(string $fixture, string $field): void
    {
        try {
            self::claims((new FakeHttpTransport())->willReturn(400, Fixtures::read('lost-orders/' . $fixture)))->find(new LostOrderCriteria());
            self::fail('Ожидалось исключение');
        } catch (LostOrderValidationException $e) {
            self::assertInstanceOf(HttpException::class, $e);
            self::assertSame(400, $e->statusCode());
            self::assertArrayHasKey($field, $e->errors());
            self::assertStringContainsString($field, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validationErrors(): iterable
    {
        yield 'дата' => ['error-400-start-date.json', 'start_date'];
        yield 'магазин' => ['error-400-merchant.json', 'merchant_id'];
        yield 'состояние' => ['error-400-ticket-state.json', 'ticket_state'];
    }

    public function testOtherErrors(): void
    {
        foreach (['error-401-no-header.json', 'error-401-bad-token.json'] as $fixture) {
            try {
                self::claims((new FakeHttpTransport())->willReturn(401, Fixtures::read('lost-orders/' . $fixture)))->find(new LostOrderCriteria());
                self::fail('Ожидалось исключение');
            } catch (AuthenticationException $e) {
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage() . $e->responseSnippet() . $e->url());
            }
        }
        foreach ([[406, Fixtures::read('lost-orders/error-406.json')], [500, Fixtures::read('orders/error-500.html')]] as [$status, $body]) {
            try {
                self::claims((new FakeHttpTransport())->willReturn($status, $body))->find(new LostOrderCriteria());
                self::fail('Ожидалось исключение');
            } catch (HttpException $e) {
                self::assertNotInstanceOf(LostOrderValidationException::class, $e);
                self::assertSame($status, $e->statusCode());
            }
        }
        $timeout = new TimeoutException('таймаут', 'GET', self::URL, 28);
        try {
            self::claims((new FakeHttpTransport())->willThrow($timeout))->find(new LostOrderCriteria());
            self::fail('Ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertSame($timeout, $e);
        }
    }

    public function testTokenIsRequiredAndValidated(): void
    {
        foreach ([null, "tok\r\nX-Evil: 1", 'tok en', "tok\n", "tok\r"] as $token) {
            $transport = new FakeHttpTransport();
            $claims = new HttpLostOrderClaims(new ApiClient($transport), $token, FrozenClock::at('2026-10-07T10:00:00Z'));
            foreach ([
                static fn () => $claims->find(new LostOrderCriteria()),
                static fn () => $claims->get(new LostOrderClaimId(1)),
                static fn () => $claims->submit(self::newClaim()),
            ] as $call) {
                try {
                    $call();
                    self::fail('Ожидалось исключение');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('токен', $e->getMessage());
                    self::assertStringNotContainsString('X-Evil', $e->getMessage());
                }
            }
            self::assertSame([], $transport->requests());
        }
    }

    public function testGet(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('lost-orders/claim-synthetic.json'));
        $claim = self::claims($transport)->get(new LostOrderClaimId(5796));
        self::assertSame(5796, $claim?->id()->value());
        self::assertSame(self::URL . '5796/', $transport->lastRequest()->uri());

        self::assertNull(self::claims((new FakeHttpTransport())->willReturn(404, Fixtures::read('lost-orders/error-404.json')))->get(new LostOrderClaimId(1)));

        try {
            self::claims((new FakeHttpTransport())->willReturn(404, '<html>Not Found</html>'))->get(new LostOrderClaimId(1));
            self::fail('Ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame(404, $e->statusCode());
        }

        $this->expectException(UnexpectedResponseException::class);
        self::claims((new FakeHttpTransport())->willReturn(200, Fixtures::read('lost-orders/claim-synthetic.json')))->get(new LostOrderClaimId(1));
    }

    public function testSubmitSendsExactMultipart(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(201, Fixtures::read('lost-orders/claim-synthetic.json'));
        $claims = new HttpLostOrderClaims(new ApiClient($transport), self::TOKEN, FrozenClock::at('2026-10-06T22:30:00Z'), boundary: static fn (): string => 'XyZ');

        $created = $claims->submit(self::newClaim(description: 'Заказ с сайта', date: '2026-07-07'));

        self::assertSame(5796, $created->id()->value());
        self::assertCount(1, $transport->requests());
        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method());
        self::assertSame(self::URL, $request->uri());
        self::assertSame('multipart/form-data; boundary=XyZ', $request->header('Content-Type'));
        self::assertSame('Bearer ' . self::TOKEN, $request->header('Authorization'));
        self::assertSame('application/json', $request->header('Accept'));
        self::assertSame(
            "--XyZ\r\nContent-Disposition: form-data; name=\"order_id\"\r\n\r\nGS123L\r\n"
            . "--XyZ\r\nContent-Disposition: form-data; name=\"order_date\"\r\n\r\n2026-07-07\r\n"
            . "--XyZ\r\nContent-Disposition: form-data; name=\"order_total\"\r\n\r\n554.34\r\n"
            . "--XyZ\r\nContent-Disposition: form-data; name=\"merchant_id\"\r\n\r\n2573\r\n"
            . "--XyZ\r\nContent-Disposition: form-data; name=\"description\"\r\n\r\nЗаказ с сайта\r\n"
            . "--XyZ\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"receipt.pdf\"\r\nContent-Type: application/pdf\r\n\r\n"
            . self::PDF . "\r\n--XyZ--\r\n",
            $request->body(),
        );
    }

    public function testSubmitSuccessVariants(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('lost-orders/claim-synthetic.json'));

        self::claims($transport)->submit(self::newClaim());

        self::assertStringNotContainsString('name="description"', (string) $transport->lastRequest()->body());
    }

    public function testSubmitRejected(): void
    {
        $transport = (new FakeHttpTransport())->willReturn(400, Fixtures::read('lost-orders/error-400-create-synthetic.json'));
        try {
            self::claims($transport)->submit(self::newClaim());
            self::fail('Ожидалось исключение');
        } catch (LostOrderValidationException $e) {
            self::assertSame(['order_date', 'attachment'], array_keys($e->errors()));
        }
        self::assertCount(1, $transport->requests(), 'без повтора');

        foreach ([[400, '<html>Bad Request</html>', HttpException::class], [413, '', HttpException::class], [401, '{"errors":{"detail":"Недопустимый токен."}}', AuthenticationException::class]] as [$status, $body, $class]) {
            try {
                self::claims((new FakeHttpTransport())->willReturn($status, $body))->submit(self::newClaim());
                self::fail('Ожидалось исключение: ' . $status);
            } catch (HttpException $e) {
                self::assertInstanceOf($class, $e);
                self::assertNotInstanceOf(LostOrderValidationException::class, $e);
            }
        }
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $prepare
     */
    #[DataProvider('unknownOutcomes')]
    public function testSubmitUnknownOutcomeIsNotRetried(\Closure $prepare): void
    {
        $transport = $prepare(new FakeHttpTransport());

        try {
            self::claims($transport)->submit(self::newClaim());
            self::fail('Ожидалось исключение');
        } catch (LostOrderClaimUnconfirmedException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertStringContainsString('lostOrders()', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        self::assertCount(1, $transport->requests(), 'POST не повторяется');
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport}>
     */
    public static function unknownOutcomes(): iterable
    {
        yield 'таймаут' => [static fn (FakeHttpTransport $t): FakeHttpTransport => $t->willThrow(new TimeoutException('таймаут', 'POST', self::URL, 28))];
        foreach ([52, 56, 80, null] as $code) {
            yield 'обрыв cURL ' . var_export($code, true) => [static fn (FakeHttpTransport $t): FakeHttpTransport => $t->willThrow(new TransportException('обрыв', 'POST', self::URL, $code))];
        }
        foreach ([500, 502, 504] as $status) {
            yield 'HTTP ' . $status => [static fn (FakeHttpTransport $t): FakeHttpTransport => $t->willReturn($status, 'error')];
        }
        yield '201 не JSON' => [static fn (FakeHttpTransport $t): FakeHttpTransport => $t->willReturn(201, 'не json')];
        yield '201 без id' => [static fn (FakeHttpTransport $t): FakeHttpTransport => $t->willReturn(201, '{"order_id":"GS123L"}')];
    }

    public function testSubmitConnectionErrorBeforeSendingPassesThrough(): void
    {
        foreach ([6, 7, 35] as $code) {
            $error = new TransportException('нет соединения', 'POST', self::URL, $code);
            try {
                self::claims((new FakeHttpTransport())->willThrow($error))->submit(self::newClaim());
                self::fail('Ожидалось исключение');
            } catch (TransportException $e) {
                self::assertSame($error, $e, 'заявка точно не создана');
            }
        }

        $transport = new FakeHttpTransport();
        try {
            self::claims($transport)->submit(self::newClaim(date: '2026-07-06'));
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('2026-07-06', $e->getMessage());
        }
        self::assertSame([], $transport->requests());
    }

    public function testTokenIsHiddenFromDumpsAndSerialization(): void
    {
        $claims = self::claims(new FakeHttpTransport());

        ob_start();
        var_dump($claims);
        $dump = (string) ob_get_clean() . print_r($claims, true);
        self::assertStringNotContainsString(self::TOKEN, $dump);

        $this->expectException(InvalidArgumentException::class);
        serialize($claims);
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, \Closure(HttpLostOrderClaims): mixed, class-string<\Throwable>}>
     */
    public static function claimFailures(): iterable
    {
        $claim = str_replace(['"2342lost"', '12020.22', '"Заказ после перехода с моего сайта"'], [
            '"' . PersonalDataMarkers::ORDER . '"',
            PersonalDataMarkers::TOTAL,
            '"' . PersonalDataMarkers::MAIL . '"',
        ], Fixtures::read('lost-orders/claim-synthetic.json'));
        self::assertStringContainsString(PersonalDataMarkers::MAIL, $claim);
        $find = static fn (HttpLostOrderClaims $c): mixed => $c->find(new LostOrderCriteria());
        $get = static fn (HttpLostOrderClaims $c): mixed => $c->get(new LostOrderClaimId(5796));
        $submit = static fn (HttpLostOrderClaims $c): mixed => $c->submit(self::markedClaim());

        yield 'find: 400 с эхом' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(400, '{"errors":{"start_date":["Нет заказа ' . PersonalDataMarkers::ORDER . '"]}}'),
            $find,
            LostOrderValidationException::class,
        ];
        yield 'find: обрезанный JSON' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, '[' . substr($claim, 0, -5)), $find, UnexpectedResponseException::class];
        yield 'find: ни одна заявка не разобрана' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, '[' . str_replace('"in_waiting"', '"zzz"', $claim) . ']'),
            $find,
            UnexpectedResponseException::class,
        ];
        yield 'get: битая дата' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, str_replace('"2026-09-24"', '"2026-02-31"', $claim)),
            $get,
            UnexpectedResponseException::class,
        ];
        yield 'get: 404 не по формату' => [static fn (FakeHttpTransport $t) => $t->willReturn(404, '<h1>' . PersonalDataMarkers::ORDER . '</h1>'), $get, HttpException::class];
        yield 'submit: 400 с эхом' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(400, '{"errors":{"order_id":["Заявка на заказ ' . PersonalDataMarkers::ORDER . ' уже есть"]}}'),
            $submit,
            LostOrderValidationException::class,
        ];
        yield 'submit: 400 не по формату' => [static fn (FakeHttpTransport $t) => $t->willReturn(400, '<p>' . PersonalDataMarkers::ORDER . '</p>'), $submit, HttpException::class];
        yield 'submit: 401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, '{"detail":"Недопустимый токен."}'), $submit, AuthenticationException::class];
        yield 'submit: 503' => [static fn (FakeHttpTransport $t) => $t->willReturn(503, 'error'), $submit, LostOrderClaimUnconfirmedException::class];
        yield 'submit: таймаут' => [static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out'), $submit, LostOrderClaimUnconfirmedException::class];
        yield 'submit: ошибка соединения' => [static fn (FakeHttpTransport $t) => $t->willFail(7), $submit, TransportException::class];
        yield 'submit: 201 не JSON' => [static fn (FakeHttpTransport $t) => $t->willReturn(201, '<html>' . PersonalDataMarkers::ORDER), $submit, LostOrderClaimUnconfirmedException::class];
        yield 'submit: 201 с битой суммой' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(201, str_replace(PersonalDataMarkers::TOTAL, '"abc"', $claim)),
            $submit,
            LostOrderClaimUnconfirmedException::class,
        ];
        yield 'submit: дата вне окна' => [
            static fn (FakeHttpTransport $t) => $t,
            static fn (HttpLostOrderClaims $c): mixed => $c->submit(self::markedClaim(date: '2026-07-06')),
            InvalidArgumentException::class,
        ];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param \Closure(HttpLostOrderClaims): mixed           $call
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('claimFailures')]
    public function testClaimDataIsHiddenFromTrace(\Closure $response, \Closure $call, string $expected): void
    {
        self::requireSensitiveParameter();
        $claims = self::claims($response(new FakeHttpTransport()));

        $e = self::catchWithTraceArgs(static fn () => $call($claims), $expected);

        self::assertTraceHidesSecrets(
            $e,
            PersonalDataMarkers::ORDER,
            PersonalDataMarkers::TOTAL,
            PersonalDataMarkers::MAIL,
            PersonalDataMarkers::PHONE,
            PersonalDataMarkers::RECEIPT_MARK,
        );
    }

    public function testSubmitTraceIsSmallWithLargeReceipt(): void
    {
        self::requireSensitiveParameter();
        $claims = self::claims((new FakeHttpTransport())->willReturn(503, 'error'));
        $claim = self::markedClaim(receipt: PersonalDataMarkers::RECEIPT . str_repeat('x', 1048576));

        $e = self::catchWithTraceArgs(static fn () => $claims->submit($claim), LostOrderClaimUnconfirmedException::class);

        self::assertTraceArgsSmallerThan($e, 65536);
    }

    private static function markedClaim(string $date = '2026-09-24', string $receipt = PersonalDataMarkers::RECEIPT): NewLostOrderClaim
    {
        return new NewLostOrderClaim(
            PersonalDataMarkers::ORDER,
            $date,
            PersonalDataMarkers::TOTAL,
            2573,
            ClaimAttachment::fromContents('receipt.pdf', $receipt),
            PersonalDataMarkers::MAIL . ' ' . PersonalDataMarkers::PHONE,
        );
    }

    private static function claims(FakeHttpTransport $transport): HttpLostOrderClaims
    {
        return new HttpLostOrderClaims(new ApiClient($transport), self::TOKEN, FrozenClock::at('2026-10-07T10:00:00Z'));
    }

    private static function newClaim(?string $description = null, string $date = '2026-09-24'): NewLostOrderClaim
    {
        return new NewLostOrderClaim('GS123L', $date, '554.34', 2573, ClaimAttachment::fromContents('receipt.pdf', self::PDF), $description);
    }

    public function testValidationMessageHasNoServerEcho(): void
    {
        try {
            self::claims((new FakeHttpTransport())->willReturn(400, '{"errors":{"order_id":["Заявка на заказ GS123L уже есть"]}}'))->submit(self::newClaim());
            self::fail('Ожидалось исключение');
        } catch (LostOrderValidationException $e) {
            self::assertStringContainsString('order_id', $e->getMessage());
            self::assertStringNotContainsString('GS123L', $e->getMessage());
            self::assertSame(['order_id' => ['Заявка на заказ GS123L уже есть']], $e->errors());
        }
    }

    public function testCreatedButUnreadableResponseIsReportedAsCreated(): void
    {
        try {
            self::claims((new FakeHttpTransport())->willReturn(201, '{"id":5800,"order_status":null}'))->submit(self::newClaim());
            self::fail('Ожидалось исключение');
        } catch (LostOrderClaimUnconfirmedException $e) {
            self::assertTrue($e->wasCreated());
            self::assertSame(5800, $e->claimId());
            self::assertStringContainsString('создана', $e->getMessage());
        }

        try {
            self::claims((new FakeHttpTransport())->willReturn(201, 'не json'))->submit(self::newClaim());
            self::fail('Ожидалось исключение');
        } catch (LostOrderClaimUnconfirmedException $e) {
            self::assertTrue($e->wasCreated());
            self::assertNull($e->claimId());
        }

        try {
            self::claims((new FakeHttpTransport())->willThrow(new TimeoutException('таймаут', 'POST', self::URL, 28)))->submit(self::newClaim());
            self::fail('Ожидалось исключение');
        } catch (LostOrderClaimUnconfirmedException $e) {
            self::assertFalse($e->wasCreated());
            self::assertNull($e->claimId());
        }
    }
}
