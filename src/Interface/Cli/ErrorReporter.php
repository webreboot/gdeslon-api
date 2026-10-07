<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteriaRejectedException;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Исключение → код выхода и текст для stderr. Сообщения исключений пакета уже без секретов; трассировка не выводится.
 *
 * @internal
 */
final class ErrorReporter
{
    private function __construct()
    {
    }

    /**
     * @return array{int, string}
     */
    public static function report(\Throwable $error): array
    {
        return match (true) {
            $error instanceof UsageException => [ExitCode::USAGE, 'Ошибка: ' . $error->getMessage() . "\n"],
            $error instanceof DuplicateLostOrderClaimException => [ExitCode::CLAIM_DUPLICATE, $error->getMessage() . "\n"],
            $error instanceof LostOrderClaimUnconfirmedException => [ExitCode::CLAIM_UNCONFIRMED, self::unconfirmed($error)],
            $error instanceof LostOrderValidationException => [ExitCode::FAILURE, self::fieldErrors('API отклонило заявку (не создана):', $error->errors())],
            $error instanceof CouponCriteriaRejectedException => [ExitCode::FAILURE, self::fieldErrors('API отклонило фильтр купонов:', $error->errors())],
            $error instanceof MissingCredentialsException => [ExitCode::ACCESS, 'Ошибка: ' . $error->getMessage() . "\n"],
            $error instanceof AuthenticationException => [ExitCode::ACCESS, 'Ошибка доступа: ' . $error->getMessage() . "\n"],
            $error instanceof InvalidArgumentException => [ExitCode::USAGE, 'Ошибка: ' . $error->getMessage() . "\n"],
            $error instanceof GdeSlonException => [ExitCode::FAILURE, 'Ошибка: ' . $error->getMessage() . "\n"],
            default => [ExitCode::FAILURE, sprintf("Внутренняя ошибка (%s): %s\n", $error::class, $error->getMessage())],
        };
    }

    private static function unconfirmed(LostOrderClaimUnconfirmedException $error): string
    {
        $text = "НЕ ПОВТОРЯЙТЕ отправку: заявка могла быть создана.\n";
        if ($error->wasCreated()) {
            $text .= sprintf("Сервер подтвердил, что заявка создана%s, но ответ не разобран.\n", $error->claimId() === null ? '' : sprintf(' (ID %d)', $error->claimId()));
        }

        return $text . $error->getMessage() . "\n";
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function fieldErrors(string $title, array $errors): string
    {
        $text = $title . "\n";
        foreach ($errors as $field => $messages) {
            $text .= sprintf("  %s: %s\n", $field, implode(' ', $messages));
        }

        return $text;
    }
}
