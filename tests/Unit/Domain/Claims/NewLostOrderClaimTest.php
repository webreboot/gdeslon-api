<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Claims;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class NewLostOrderClaimTest extends TestCase
{
    public function testFullClaim(): void
    {
        $attachment = self::attachment();
        $claim = new NewLostOrderClaim(
            orderNumber: ' GS123L ',
            orderDate: '2026-09-24',
            orderTotal: '554.34',
            merchant: 2573,
            attachment: $attachment,
            description: "Заказ после перехода\r\nс моего сайта ",
        );

        self::assertSame('GS123L', $claim->orderNumber());
        self::assertSame('2026-09-24', $claim->orderDate());
        self::assertSame('554.34', $claim->orderTotal()->amount());
        self::assertTrue($claim->merchant()->equals(new MerchantId(2573)));
        self::assertSame($attachment, $claim->attachment());
        self::assertSame("Заказ после перехода\nс моего сайта", $claim->description());
    }

    public function testAlternativeTypes(): void
    {
        $claim = new NewLostOrderClaim(
            orderNumber: str_repeat('ж', 255),
            orderDate: new \DateTimeImmutable('2026-09-24 23:30', new \DateTimeZone('+03:00')),
            orderTotal: OrderTotal::of(100),
            merchant: new MerchantId(1),
            attachment: self::attachment(),
            description: '  ',
        );

        self::assertSame('2026-09-24', $claim->orderDate(), 'день в часовом поясе самой даты');
        self::assertNull($claim->description());
        self::assertNull((new NewLostOrderClaim('1', '2026-09-24', 1, 1, self::attachment(), ''))->description());
    }

    /**
     * @param \Closure(): NewLostOrderClaim $create
     */
    #[DataProvider('invalidClaims')]
    public function testInvalid(\Closure $create, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /**
     * @return iterable<string, array{\Closure(): NewLostOrderClaim, string}>
     */
    public static function invalidClaims(): iterable
    {
        $make = static fn (string $number = 'GS123L', string $date = '2026-09-24', int $merchant = 2573, ?string $description = null): NewLostOrderClaim
            => new NewLostOrderClaim($number, $date, '554.34', $merchant, self::attachment(), $description);

        yield 'пустой номер' => [static fn (): NewLostOrderClaim => $make(' '), 'Номер заказа'];
        yield 'номер 256 символов' => [static fn (): NewLostOrderClaim => $make(str_repeat('ж', 256)), '255'];
        yield 'перевод строки в номере' => [static fn (): NewLostOrderClaim => $make("GS\n123"), 'Номер заказа'];
        yield 'номер не UTF-8' => [static fn (): NewLostOrderClaim => $make("GS\xff"), 'UTF-8'];
        yield '31 февраля' => [static fn (): NewLostOrderClaim => $make(date: '2026-02-31'), '2026-02-31'];
        yield 'дата по-русски' => [static fn (): NewLostOrderClaim => $make(date: '24.07.2018'), '24.07.2018'];
        yield 'пустая дата' => [static fn (): NewLostOrderClaim => $make(date: ''), 'Y-m-d'];
        yield 'магазин 0' => [static fn (): NewLostOrderClaim => $make(merchant: 0), 'магазина'];
        yield 'описание 256' => [static fn (): NewLostOrderClaim => $make(description: str_repeat('ж', 256)), '255'];
        yield 'описание не UTF-8' => [static fn (): NewLostOrderClaim => $make(description: "\xff"), 'UTF-8'];
    }

    public function testThreeMonthWindow(): void
    {
        $today = new \DateTimeImmutable('2026-10-07');
        foreach (['2026-10-07', '2026-07-07', '2026-08-15'] as $date) {
            self::claim($date)->assertOrderDateWithin($today);
        }
        foreach (['2026-10-08', '2026-07-06'] as $date) {
            self::assertOutsideWindow($date, $today);
        }

        self::claim('2026-02-28')->assertOrderDateWithin(new \DateTimeImmutable('2026-05-31'));
        self::assertOutsideWindow('2026-02-27', new \DateTimeImmutable('2026-05-31'));
        self::claim('2028-02-29')->assertOrderDateWithin(new \DateTimeImmutable('2028-05-31'));
        self::assertOutsideWindow('2028-02-28', new \DateTimeImmutable('2028-05-31'));
    }

    private static function assertOutsideWindow(string $date, \DateTimeImmutable $today): void
    {
        try {
            self::claim($date)->assertOrderDateWithin($today);
            self::fail('Ожидалось исключение: ' . $date);
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($date, $e->getMessage());
        }
    }

    private static function claim(string $date): NewLostOrderClaim
    {
        return new NewLostOrderClaim('GS123L', $date, '554.34', 2573, self::attachment());
    }

    private static function attachment(): ClaimAttachment
    {
        return ClaimAttachment::fromContents('receipt.pdf', ClaimAttachmentTest::PDF);
    }
}
