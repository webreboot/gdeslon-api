<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

/**
 * Коды выхода gdeslon — публичный контракт CLI (docs/cli.md).
 *
 * @internal
 */
final class ExitCode
{
    public const OK = 0;

    /** Ошибка выполнения: сеть, HTTP, битый ответ, отказ API, «не найдено», отмена, внутренняя ошибка. */
    public const FAILURE = 1;

    /** Неверный вызов: команда, опции, значения, ограничения до запроса. */
    public const USAGE = 2;

    /** Нет ключей для команды или ключ не принят API. */
    public const ACCESS = 3;

    /** Заявка на потерянный заказ могла быть создана — НЕ повторять. */
    public const CLAIM_UNCONFIRMED = 4;

    /** Заявка на этот заказ уже есть — новая не отправлена. */
    public const CLAIM_DUPLICATE = 5;

    private function __construct()
    {
    }
}
