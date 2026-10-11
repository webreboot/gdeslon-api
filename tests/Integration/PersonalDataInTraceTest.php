<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;
use Webreboot\GdeSlon\Tests\Support\PersonalDataMarkers;
use Webreboot\GdeSlon\Tests\Support\TraceSecrets;

final class PersonalDataInTraceTest extends TestCase
{
    use TraceSecrets;

    private const MARKERS = [
        PersonalDataMarkers::ORDER,
        PersonalDataMarkers::TOTAL,
        PersonalDataMarkers::MAIL,
        PersonalDataMarkers::PHONE,
        PersonalDataMarkers::RECEIPT_MARK,
        PersonalDataMarkers::SUB,
    ];

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, bool, class-string<\Throwable>}>
     */
    public static function submitFailures(): iterable
    {
        $claims = self::markedClaims();

        yield 'дубль' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, $claims), true, DuplicateLostOrderClaimException::class];
        yield 'проверка дублей: 401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, '{"detail":"Недопустимый токен."}'), true, AuthenticationException::class];
        yield 'проверка дублей: обрезанный JSON' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, self::cutAfterOrder($claims)), true, UnexpectedResponseException::class];
        yield 'проверка дублей: битая заявка в списке' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, str_replace('"in_waiting"', '"zzz"', $claims)),
            true,
            UnexpectedResponseException::class,
        ];
        yield '400' => [static fn (FakeHttpTransport $t) => $t->willReturn(400, Fixtures::read('lost-orders/error-400-create-synthetic.json')), false, LostOrderValidationException::class];
        yield '503' => [static fn (FakeHttpTransport $t) => $t->willReturn(503, 'error'), false, LostOrderClaimUnconfirmedException::class];
        yield '201 не JSON' => [static fn (FakeHttpTransport $t) => $t->willReturn(201, '{"id":5796,"order_id":"' . PersonalDataMarkers::ORDER), false, LostOrderClaimUnconfirmedException::class];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('submitFailures')]
    public function testSubmitLostOrderClaim(\Closure $response, bool $checkDuplicates, string $expected): void
    {
        self::requireSensitiveParameter();
        $gdeslon = self::gdeslon($response(new FakeHttpTransport()));
        $claim = self::markedClaim();

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->submitLostOrderClaim($claim, $checkDuplicates), $expected);

        self::assertTraceHidesSecrets($e, ...self::MARKERS);
        self::assertSubmitFrameHidesClaim($e);
    }

    public function testSubmitOutsideWindowHidesClaim(): void
    {
        self::requireSensitiveParameter();
        $transport = new FakeHttpTransport();
        $claim = self::markedClaim('2026-07-06');

        $e = self::catchWithTraceArgs(static fn () => self::gdeslon($transport)->submitLostOrderClaim($claim), InvalidArgumentException::class);

        self::assertTraceHidesSecrets($e, ...self::MARKERS);
        self::assertSubmitFrameHidesClaim($e);
        self::assertSame([], $transport->requests());
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, \Closure(GdeSlon): mixed}>
     */
    public static function readFailures(): iterable
    {
        $orders = str_replace(['"blog"', '"A-1001"', '"1500.00"', '"купить чайник"'], [
            '"' . PersonalDataMarkers::SUB . '"',
            '"' . PersonalDataMarkers::ORDER . '"',
            '"' . PersonalDataMarkers::TOTAL . '"',
            '"' . PersonalDataMarkers::MAIL . '"',
        ], Fixtures::read('orders/orders-synthetic.json'));
        $claims = self::markedClaims();
        $claim = str_replace('"2342lost"', '"' . PersonalDataMarkers::ORDER . '"', Fixtures::read('lost-orders/claim-synthetic.json'));
        $ordersCall = static fn (GdeSlon $g): mixed => $g->orders(new OrderCriteria(subId: PersonalDataMarkers::SUB));

        yield 'orders: обрезанный JSON' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, substr($orders, 0, (int) strpos($orders, PersonalDataMarkers::MAIL) + 40)), $ordersCall];
        yield 'orders: ни один заказ не разобран' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, (string) preg_replace('/"state": "?\d+"?/', '"state": 9', $orders)), $ordersCall];
        yield 'lostOrders: обрезанный JSON' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, self::cutAfterOrder($claims)), static fn (GdeSlon $g): mixed => $g->lostOrders()];
        yield 'lostOrders: ни одна заявка не разобрана' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, str_replace('"order_status": "', '"order_status": "zz', $claims)),
            static fn (GdeSlon $g): mixed => $g->lostOrders(),
        ];
        yield 'lostOrder: битая дата' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, str_replace('"2026-09-24"', '"2026-02-31"', $claim)),
            static fn (GdeSlon $g): mixed => $g->lostOrder(5796),
        ];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param \Closure(GdeSlon): mixed                       $call
     */
    #[DataProvider('readFailures')]
    public function testOrdersAndLostOrders(\Closure $response, \Closure $call): void
    {
        self::requireSensitiveParameter();
        $gdeslon = self::gdeslon($response(new FakeHttpTransport()));

        $e = self::catchWithTraceArgs(static fn () => $call($gdeslon), UnexpectedResponseException::class);

        self::assertTraceHidesSecrets($e, ...self::MARKERS);
    }

    /**
     * @return iterable<string, array{\Closure(GdeSlon): mixed, class-string<\Throwable>}>
     */
    public static function brokenJsonCalls(): iterable
    {
        yield 'categories' => [static fn (GdeSlon $g): mixed => $g->categories(), UnexpectedResponseException::class];
        yield 'orders' => [static fn (GdeSlon $g): mixed => $g->orders(), UnexpectedResponseException::class];
        yield 'lostOrders' => [static fn (GdeSlon $g): mixed => $g->lostOrders(), UnexpectedResponseException::class];
        yield 'lostOrder' => [static fn (GdeSlon $g): mixed => $g->lostOrder(5796), UnexpectedResponseException::class];
        yield 'submitLostOrderClaim, 201' => [
            static fn (GdeSlon $g): mixed => $g->submitLostOrderClaim(self::markedClaim(), checkDuplicates: false),
            LostOrderClaimUnconfirmedException::class,
        ];
    }

    /**
     * @param \Closure(GdeSlon): mixed $call
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('brokenJsonCalls')]
    public function testJsonErrorsHaveNoJsonDecodeFrame(\Closure $call, string $expected): void
    {
        $gdeslon = self::gdeslon((new FakeHttpTransport())->willReturn(200, '[{"sub_id":"' . PersonalDataMarkers::SUB . '",'));

        $e = self::catchWithTraceArgs(static fn () => $call($gdeslon), $expected);

        self::assertNoFrame($e, 'json_decode');
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            self::assertNotInstanceOf(\JsonException::class, $current);
        }
    }

    private static function assertSubmitFrameHidesClaim(\Throwable $e): void
    {
        foreach ($e->getTrace() as $frame) {
            if (($frame['class'] ?? '') === GdeSlon::class && $frame['function'] === 'submitLostOrderClaim') {
                self::assertStringContainsString('SensitiveParameterValue', var_export($frame['args'] ?? [], true));

                return;
            }
        }

        self::fail('Нет кадра GdeSlon::submitLostOrderClaim');
    }

    private static function gdeslon(FakeHttpTransport $transport): GdeSlon
    {
        return GdeSlon::create(
            new Config(apiToken: 'test-token', userId: '1234', apiKey: 'test-api-key'),
            $transport,
            null,
            FrozenClock::at('2026-10-07T10:00:00Z'),
        );
    }

    private static function markedClaims(): string
    {
        $claims = str_replace('"GS123L"', '"' . PersonalDataMarkers::ORDER . '"', Fixtures::read('lost-orders/claims-synthetic.json'));
        self::assertStringContainsString(PersonalDataMarkers::ORDER, $claims);

        return $claims;
    }

    private static function cutAfterOrder(string $claims): string
    {
        return substr($claims, 0, (int) strpos($claims, PersonalDataMarkers::ORDER) + 40);
    }

    private static function markedClaim(string $date = '2026-09-24'): NewLostOrderClaim
    {
        return new NewLostOrderClaim(
            PersonalDataMarkers::ORDER,
            $date,
            PersonalDataMarkers::TOTAL,
            2573,
            ClaimAttachment::fromContents('receipt.pdf', PersonalDataMarkers::RECEIPT),
            PersonalDataMarkers::MAIL . ' ' . PersonalDataMarkers::PHONE,
        );
    }
}
