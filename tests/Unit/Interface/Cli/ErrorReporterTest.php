<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteriaRejectedException;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Interface\Cli\ErrorReporter;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\MissingCredentialsException;
use Webreboot\GdeSlon\Interface\Cli\UsageException;

final class ErrorReporterTest extends TestCase
{
    #[DataProvider('errors')]
    public function testExitCodes(\Throwable $error, int $code, string $message): void
    {
        [$actualCode, $text] = ErrorReporter::report($error);

        self::assertSame($code, $actualCode);
        self::assertStringContainsString($message, $text);
        self::assertStringNotContainsString('#0 ', $text, 'без трассировки');
        self::assertStringEndsWith("\n", $text);
    }

    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function errors(): iterable
    {
        $claim = new LostOrderClaim(5796, 'GS123L', new \DateTimeImmutable('2026-09-24'), OrderTotal::of('1'), 2573, LostOrderStatus::Waiting, LostOrderClaimState::InWork);

        yield 'вызов' => [new UsageException('Неизвестная команда «x»'), ExitCode::USAGE, 'Неизвестная команда'];
        yield 'дубль' => [new DuplicateLostOrderClaimException($claim), ExitCode::CLAIM_DUPLICATE, '5796'];
        yield 'не подтверждена' => [new LostOrderClaimUnconfirmedException(new TimeoutException('таймаут', 'POST', 'https://h/', 28)), ExitCode::CLAIM_UNCONFIRMED, 'НЕ ПОВТОРЯЙТЕ'];
        yield 'создана, ответ не разобран' => [new LostOrderClaimUnconfirmedException(new UnexpectedResponseException('битый'), 201, 9001), ExitCode::CLAIM_UNCONFIRMED, '9001'];
        yield 'отказ заявки' => [new LostOrderValidationException('HTTP 400', 400, 'POST', 'https://h/', '', ['order_date' => ['Слишком старая']]), ExitCode::FAILURE, 'order_date: Слишком старая'];
        yield 'отказ фильтра купонов' => [new CouponCriteriaRejectedException('HTTP 400', 400, 'GET', 'https://h/', '', ['merchant_id' => ['Нет такого']]), ExitCode::FAILURE, 'merchant_id: Нет такого'];
        yield 'доступ' => [new AuthenticationException('HTTP 401', 401, 'GET', 'https://h/', ''), ExitCode::ACCESS, 'HTTP 401'];
        yield 'нет ключей' => [new MissingCredentialsException('Нужен токен XML API: GDESLON_API_TOKEN'), ExitCode::ACCESS, 'GDESLON_API_TOKEN'];
        yield 'неверные критерии' => [new InvalidArgumentException('limit должен быть от 1 до 100'), ExitCode::USAGE, 'limit'];
        yield 'HTTP' => [new HttpException('HTTP 500', 500, 'GET', 'https://h/', ''), ExitCode::FAILURE, 'HTTP 500'];
        yield 'сеть' => [new TimeoutException('таймаут', 'GET', 'https://h/', 28), ExitCode::FAILURE, 'таймаут'];
        yield 'внутренняя' => [new \RuntimeException('boom'), ExitCode::FAILURE, 'Внутренняя ошибка (RuntimeException): boom'];
        yield 'Error' => [new \TypeError('bad'), ExitCode::FAILURE, 'Внутренняя ошибка (TypeError)'];
    }

    public function testCodes(): void
    {
        self::assertSame([0, 1, 2, 3, 4, 5], [ExitCode::OK, ExitCode::FAILURE, ExitCode::USAGE, ExitCode::ACCESS, ExitCode::CLAIM_UNCONFIRMED, ExitCode::CLAIM_DUPLICATE]);
    }
}
