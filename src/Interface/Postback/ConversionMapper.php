<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Domain\Sales\Conversion;
use Webreboot\GdeSlon\Domain\Sales\OrderState;

/**
 * Параметры postback (имена — по карте полей) → Conversion и предупреждения.
 *
 * Обязательны `merchant_id` и `state`: без них или с битыми значениями — InvalidPostbackException (400). Остальные поля
 * терпимы: пустое — null без предупреждения, битое — null и предупреждение с именем поля, чтобы конверсия не терялась.
 * Формат времени и сумм в реальном postback не проверен (docs/gdeslon-api/postback.md): время — ISO 8601, «Y-m-d
 * H:i:s[.u]», «Y-m-d» (без пояса — Москва) или unix-время; суммы — «123.45». Значения sub_id, user_agent, offer_name,
 * номеров заказов в предупреждения и сообщения не пишутся.
 *
 * @internal
 */
final class ConversionMapper
{
    private const TIMEZONE = 'Europe/Moscow';

    private const DATE_PATTERN = '~^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?$~';

    private const STATE_NAMES = [
        'created' => OrderState::New,
        'cancelled' => OrderState::Cancelled,
        'pending' => OrderState::Pending,
        'confirmed' => OrderState::Confirmed,
        'payed' => OrderState::Paid,
    ];

    private const SUB_IDS = [
        1 => PostbackMacro::SubId,
        2 => PostbackMacro::SubId2,
        3 => PostbackMacro::SubId3,
        4 => PostbackMacro::SubId4,
        5 => PostbackMacro::SubId5,
    ];

    public function __construct(private readonly PostbackFields $fields)
    {
    }

    /**
     * @param array<string, string|NonScalarValue> $parameters
     *
     * @return array{Conversion, list<string>} конверсия и предупреждения о битых необязательных полях
     *
     * @throws InvalidPostbackException нет или битые merchant_id/state
     */
    public function map(array $parameters): array
    {
        $warnings = [];
        $optional = function (PostbackMacro $macro, \Closure $convert) use ($parameters, &$warnings): mixed {
            $value = $this->parameter($parameters, $macro);
            if ($value === null || $value === '') {
                return null;
            }
            try {
                if ($value instanceof NonScalarValue) {
                    throw new \UnexpectedValueException(sprintf('ожидалась строка, получен %s', $value->type()));
                }

                return $convert($value);
            } catch (\UnexpectedValueException $e) {
                $warnings[] = sprintf('%s: %s — поле пропущено', $this->label($macro), $e->getMessage());

                return null;
            }
        };

        $subIds = [];
        foreach (self::SUB_IDS as $position => $macro) {
            $subId = $optional($macro, self::text(...));
            if (is_string($subId)) {
                $subIds[$position] = $subId;
            }
        }

        $conversion = new Conversion(
            merchantId: $this->merchantId($parameters),
            state: $this->state($parameters),
            orderId: self::nonEmpty($optional(PostbackMacro::GsOrderId, self::text(...))),
            merchantOrderNumber: self::stringOrNull($optional(PostbackMacro::OrderId, self::text(...))),
            subIds: $subIds,
            reward: self::stringOrNull($optional(PostbackMacro::Profit, self::amount(...))),
            orderSum: self::stringOrNull($optional(PostbackMacro::OrderSum, self::amount(...))),
            priceInCurrency: self::stringOrNull($optional(PostbackMacro::PriceInCurrency, self::amount(...))),
            currency: self::stringOrNull($optional(PostbackMacro::Currency, self::currency(...))),
            clickedAt: self::dateOrNull($optional(PostbackMacro::ClickTime, self::time(...))),
            actionAt: self::dateOrNull($optional(PostbackMacro::ActionTime, self::time(...))),
            userAgent: self::stringOrNull($optional(PostbackMacro::UserAgent, self::text(...))),
            offerName: self::stringOrNull($optional(PostbackMacro::OfferName, self::text(...))),
            clickId: self::stringOrNull($optional(PostbackMacro::ClickId, self::text(...))),
        );

        return [$conversion, $warnings];
    }

    /**
     * @param array<string, string|NonScalarValue> $parameters
     */
    private function merchantId(array $parameters): int
    {
        $value = $this->required($parameters, PostbackMacro::MerchantId);
        $trimmed = trim($value);
        if (preg_match('/^\d{1,18}$/', $trimmed) !== 1 || ltrim($trimmed, '0') === '') {
            throw $this->invalid(PostbackMacro::MerchantId, sprintf('ожидался ID магазина, получено «%s»', PostbackText::safe($value)));
        }

        return (int) $trimmed;
    }

    /**
     * @param array<string, string|NonScalarValue> $parameters
     */
    private function state(array $parameters): OrderState
    {
        $value = $this->required($parameters, PostbackMacro::State);
        $trimmed = trim($value);
        if (preg_match('/^[0-4]$/', $trimmed) === 1) {
            return OrderState::from((int) $trimmed);
        }

        return self::STATE_NAMES[strtolower($trimmed)]
            ?? throw $this->invalid(PostbackMacro::State, sprintf('ожидался статус 0..4 или created/cancelled/pending/confirmed/payed, получено «%s»', PostbackText::safe($value)));
    }

    /**
     * @param array<string, string|NonScalarValue> $parameters
     */
    private function required(array $parameters, PostbackMacro $macro): string
    {
        $value = $this->parameter($parameters, $macro);
        if ($value === null) {
            throw $this->invalid($macro, 'нет параметра');
        }
        if ($value instanceof NonScalarValue) {
            throw $this->invalid($macro, sprintf('ожидалась строка, получен %s', $value->type()));
        }

        return $value;
    }

    /**
     * @param array<string, string|NonScalarValue> $parameters
     */
    private function parameter(array $parameters, PostbackMacro $macro): string|NonScalarValue|null
    {
        $name = $this->fields->nameOf($macro);

        return $name === null ? null : $parameters[$name] ?? null;
    }

    private function invalid(PostbackMacro $macro, string $reason): InvalidPostbackException
    {
        return InvalidPostbackException::forField($macro->value, sprintf('Postback: %s: %s', $this->label($macro), $reason));
    }

    private function label(PostbackMacro $macro): string
    {
        $name = $this->fields->nameOf($macro);

        return $name === null || $name === $macro->value ? $macro->value : sprintf('%s (параметр «%s»)', $macro->value, PostbackText::safe($name));
    }

    private static function text(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new \UnexpectedValueException('строка не в UTF-8');
        }

        return $value;
    }

    private static function amount(string $value): string
    {
        $amount = trim($value);
        if (preg_match('/^\d+(\.\d+)?\z/', $amount) !== 1) {
            throw new \UnexpectedValueException(sprintf('ожидалась неотрицательная сумма вида «123.45», получено «%s»', PostbackText::safe($value)));
        }

        return $amount;
    }

    private static function currency(string $value): string
    {
        $currency = trim($value);
        if (preg_match('/^[A-Za-z]{3}\z/', $currency) !== 1) {
            throw new \UnexpectedValueException(sprintf('ожидался код валюты из трёх латинских букв, получено «%s»', PostbackText::safe($value)));
        }

        return strtoupper($currency);
    }

    private static function time(string $value): \DateTimeImmutable
    {
        $time = trim($value);
        if (preg_match('/^\d{9,10}$/', $time) === 1) {
            return new \DateTimeImmutable('@' . $time);
        }
        $invalid = new \UnexpectedValueException(sprintf('ожидалось время «Y-m-d H:i:s» или ISO 8601, получено «%s»', PostbackText::safe($value)));
        if (preg_match(self::DATE_PATTERN, $time) !== 1) {
            throw $invalid;
        }
        try {
            $date = new \DateTimeImmutable($time, new \DateTimeZone(self::TIMEZONE));
        } catch (\Exception) {
            throw $invalid;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            // «2026-02-31» PHP молча превращает в 3 марта
            throw $invalid;
        }

        return $date;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        return $value instanceof \DateTimeImmutable ? $value : null;
    }
}
