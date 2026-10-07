<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Sales;

use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Json\JsonDecimal;

/**
 * @internal
 */
final class OrderMapper
{
    private const TIMEZONE = 'Europe/Moscow';

    private const DATE_PATTERN = '~^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?$~';

    public function toList(mixed $payload, OrderCriteria $criteria): OrderList
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new UnexpectedResponseException(sprintf(
                'Ответ заказов: ожидался JSON-массив заказов, получен %s',
                is_array($payload) ? 'объект' : get_debug_type($payload),
            ));
        }

        $orders = [];
        $skipped = [];
        foreach ($payload as $index => $record) {
            $label = sprintf('заказ #%d', $index + 1);
            try {
                if (!is_array($record) || ($record !== [] && array_is_list($record))) {
                    throw new UnexpectedResponseException(sprintf('запись не объект, а %s', is_array($record) ? 'массив' : get_debug_type($record)));
                }
                $label .= self::idLabel($record);
                $order = self::order($record);
            } catch (UnexpectedResponseException | InvalidArgumentException $e) {
                $skipped[] = sprintf('%s: %s', $label, $e->getMessage());

                continue;
            }

            $id = $order->id()->value();
            if (isset($orders[$id])) {
                $skipped[] = sprintf('%s: повтор ID, оставлена первая запись', $label);

                continue;
            }
            $orders[$id] = $order;
        }

        if ($orders === [] && $skipped !== []) {
            throw new UnexpectedResponseException(sprintf(
                'Ответ заказов: ни один заказ не разобран (%d), первый: %s',
                count($skipped),
                $skipped[0],
            ));
        }

        return new OrderList($criteria, array_values($orders), $skipped);
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function order(array $record): Order
    {
        $state = self::integer($record, 'state', true);
        $type = self::integer($record, 'type', true);
        $currency = self::currency($record);

        return new Order(
            id: self::id($record),
            merchantId: (int) self::integer($record, 'merchant_id', true),
            state: OrderState::tryFrom((int) $state) ?? throw new UnexpectedResponseException(sprintf('state: неизвестный статус %d', $state)),
            type: OrderType::tryFrom((int) $type) ?? throw new UnexpectedResponseException(sprintf('type: неизвестный тип %d', $type)),
            reward: self::money($record, 'partner_payment', $currency) ?? throw new UnexpectedResponseException('partner_payment: нет вознаграждения'),
            amount: self::money($record, 'order_payment', $currency),
            merchantOrderNumber: self::text($record, 'merchant_order_id'),
            subId: self::text($record, 'sub_id'),
            merchantName: self::text($record, 'merchant_name'),
            affiliateId: self::integer($record, 'affiliate_id'),
            itemCount: self::integer($record, 'items_in_order', allowNegative: true),
            transitionAt: self::date($record, 'transition_at'),
            createdAt: self::date($record, 'created_at'),
            lastUpdatedAt: self::date($record, 'last_updated_at'),
            confirmedAt: self::date($record, 'confirmed_at'),
            accruedAt: self::date($record, 'accrued_at'),
            keywords: self::text($record, 'keywords'),
        );
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function idLabel(array $record): string
    {
        $id = $record['gdeslon_order_id'] ?? null;

        return (is_int($id) || is_string($id)) && trim((string) $id) !== '' ? sprintf(' (%s)', substr(trim((string) $id), 0, 40)) : '';
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function id(array $record): string
    {
        $id = $record['gdeslon_order_id'] ?? null;
        if (is_int($id) && $id > 0) {
            return (string) $id;
        }
        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }

        throw new UnexpectedResponseException(sprintf('gdeslon_order_id: ожидался ID заказа, получен %s', self::describe($id)));
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function integer(array $record, string $field, bool $required = false, bool $allowNegative = false): ?int
    {
        $value = $record[$field] ?? null;
        if (is_string($value) && preg_match('/^\s*\d{1,18}\s*$/', $value) === 1) {
            return (int) $value;
        }
        if (is_int($value) && ($value >= 0 || $allowNegative)) {
            return $value;
        }
        if ($value === null && !$required) {
            return null;
        }

        throw new UnexpectedResponseException(sprintf('%s: ожидалось целое неотрицательное число, получен %s', $field, self::describe($value)));
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function currency(array $record): string
    {
        $value = $record['currency'] ?? null;
        if (is_string($value) && preg_match('/^\s*[A-Za-z]{3}\s*$/', $value) === 1) {
            return strtoupper(trim($value));
        }

        throw new UnexpectedResponseException(sprintf('currency: ожидался код валюты из трёх латинских букв, получен %s', self::describe($value)));
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function money(array $record, string $field, string $currency): ?Money
    {
        $value = $record[$field] ?? null;
        if ($value === null) {
            return null;
        }

        $amount = match (true) {
            is_string($value) => trim($value),
            is_int($value) => (string) $value,
            is_float($value) => JsonDecimal::fromFloat($value),
            default => null,
        };
        if ($amount === null || preg_match('/^\d+(\.\d+)?\z/', $amount) !== 1) {
            throw new UnexpectedResponseException(sprintf('%s: ожидалась неотрицательная сумма «1500.00», получено %s', $field, self::describe($value)));
        }

        return new Money($amount, $currency);
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function text(array $record, string $field): ?string
    {
        $value = $record[$field] ?? null;

        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => throw new UnexpectedResponseException(sprintf('%s: ожидалась строка, получен %s', $field, get_debug_type($value))),
        };
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function date(array $record, string $field): ?\DateTimeImmutable
    {
        $value = $record[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match(self::DATE_PATTERN, trim($value)) !== 1) {
            throw new UnexpectedResponseException(sprintf('%s: ожидалась дата, получено %s', $field, self::describe($value)));
        }

        try {
            $date = new \DateTimeImmutable(trim($value), new \DateTimeZone(self::TIMEZONE));
        } catch (\Exception) {
            throw new UnexpectedResponseException(sprintf('%s: неверная дата %s', $field, self::describe($value)));
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            // «2026-02-31» PHP молча превращает в 3 марта
            throw new UnexpectedResponseException(sprintf('%s: несуществующая дата %s', $field, self::describe($value)));
        }

        return $date;
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? sprintf('%s «%s»', get_debug_type($value), self::shorten(is_float($value) ? var_export($value, true) : (string) $value))
            : get_debug_type($value);
    }

    private static function shorten(string $value): string
    {
        return preg_match('/^.{0,40}/us', $value, $match) === 1 ? $match[0] : substr($value, 0, 40);
    }
}
