<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Claims;

use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;

/**
 * @internal
 */
final class LostOrderClaimMapper
{
    private const TIMEZONE = 'Europe/Moscow';

    private const DATE_PATTERN = '~^(\d{4}-\d{2}-\d{2})(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?)?$~';

    public function toList(mixed $payload, LostOrderCriteria $criteria): LostOrderClaimList
    {
        $records = self::records($payload);

        $claims = [];
        $skipped = [];
        foreach ($records as $index => $record) {
            $label = sprintf('заявка #%d', $index + 1);
            try {
                if (!is_array($record) || ($record !== [] && array_is_list($record))) {
                    throw new UnexpectedResponseException(sprintf('запись не объект, а %s', is_array($record) ? 'массив' : get_debug_type($record)));
                }
                $id = $record['id'] ?? null;
                if (is_int($id) || (is_string($id) && $id !== '')) {
                    $label .= sprintf(' (%s)', substr((string) $id, 0, 20));
                }
                $claim = $this->claim($record);
            } catch (UnexpectedResponseException | InvalidArgumentException $e) {
                $skipped[] = sprintf('%s: %s', $label, $e->getMessage());

                continue;
            }
            if (isset($claims[$claim->id()->value()])) {
                $skipped[] = sprintf('%s: повтор ID, оставлена первая запись', $label);

                continue;
            }
            $claims[$claim->id()->value()] = $claim;
        }

        if ($claims === [] && $skipped !== []) {
            throw new UnexpectedResponseException(sprintf('Ответ заявок: ни одна заявка не разобрана (%d), первая: %s', count($skipped), $skipped[0]));
        }

        return new LostOrderClaimList(
            $criteria,
            array_values(array_filter($claims, static fn (LostOrderClaim $claim): bool => $criteria->matches($claim))),
            $skipped,
        );
    }

    public function toClaim(mixed $payload): LostOrderClaim
    {
        if (!is_array($payload) || array_is_list($payload)) {
            throw new UnexpectedResponseException(sprintf('Ответ заявки: ожидался объект, получен %s', is_array($payload) ? 'массив' : get_debug_type($payload)));
        }
        try {
            return $this->claim($payload);
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedResponseException('Ответ заявки: ' . $e->getMessage(), $e);
        } catch (UnexpectedResponseException $e) {
            throw new UnexpectedResponseException('Ответ заявки: ' . $e->getMessage(), $e);
        }
    }

    /**
     * @return array<string, list<string>>|null
     */
    public function errors(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !is_array($decoded['errors'] ?? null) || $decoded['errors'] === [] || array_is_list($decoded['errors'])) {
            return null;
        }

        $errors = [];
        foreach ($decoded['errors'] as $field => $messages) {
            $messages = is_array($messages) ? $messages : [$messages];
            $errors[(string) $field] = array_values(array_map(
                static fn (mixed $message): string => is_scalar($message) ? (string) $message : (string) json_encode($message, JSON_UNESCAPED_UNICODE),
                $messages,
            ));
        }

        return $errors;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function records(mixed $payload): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }
        if (is_array($payload) && array_key_exists('results', $payload)) {
            if (($payload['next'] ?? null) !== null) {
                throw new UnexpectedResponseException('Ответ заявок: постраничный ответ со следующей страницей — по ссылкам next не переходим');
            }
            if (!is_array($payload['results']) || !array_is_list($payload['results'])) {
                throw new UnexpectedResponseException('Ответ заявок: results — не список');
            }

            return $payload['results'];
        }

        throw new UnexpectedResponseException(sprintf('Ответ заявок: ожидался JSON-массив заявок, получен %s', is_array($payload) ? 'объект' : get_debug_type($payload)));
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private function claim(array $record): LostOrderClaim
    {
        $orderNumber = $record['order_id'] ?? null;
        if (is_int($orderNumber)) {
            $orderNumber = (string) $orderNumber;
        }
        if (!is_string($orderNumber) || trim($orderNumber) === '') {
            throw new UnexpectedResponseException('order_id: нет номера заказа');
        }

        $total = $record['order_total'] ?? null;
        if (!is_string($total) && !is_int($total) && !is_float($total)) {
            throw new UnexpectedResponseException(sprintf('order_total: ожидалась сумма, получен %s', get_debug_type($total)));
        }
        try {
            $orderTotal = OrderTotal::of($total);
        } catch (InvalidArgumentException) {
            throw new UnexpectedResponseException('order_total: не неотрицательная сумма с двумя знаками после точки');
        }

        $status = $record['order_status'] ?? null;
        $state = $record['ticket_state'] ?? $record['ticket_status'] ?? null;

        return new LostOrderClaim(
            id: self::positiveInt($record, 'id'),
            orderNumber: $orderNumber,
            orderDate: self::day($record['order_date'] ?? null) ?? throw new UnexpectedResponseException('order_date: нет даты заказа'),
            orderTotal: $orderTotal,
            merchantId: self::positiveInt($record, 'merchant_id'),
            orderStatus: (is_string($status) ? LostOrderStatus::fromApi($status) : null)
                ?? throw new UnexpectedResponseException(sprintf('order_status: неизвестный статус %s', self::describe($status))),
            claimState: (is_string($state) ? LostOrderClaimState::fromApi($state) : null)
                ?? throw new UnexpectedResponseException(sprintf('ticket_state: неизвестное состояние %s', self::describe($state))),
            merchantName: self::text($record, 'merchant_name'),
            description: self::text($record, 'description'),
            attachmentUrl: self::text($record, 'attachment'),
            orderUpdatedAt: array_key_exists('order_updated_date', $record) && $record['order_updated_date'] !== null
                ? self::date($record['order_updated_date'], 'order_updated_date')
                : self::date($record['sale_updated_at'] ?? null, 'sale_updated_at'),
        );
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function positiveInt(array $record, string $field): int
    {
        $value = $record[$field] ?? null;
        if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new UnexpectedResponseException(sprintf('%s: ожидалось положительное целое, получено %s', $field, self::describe($value)));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $record
     */
    private static function text(array $record, string $field): ?string
    {
        $value = $record[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new UnexpectedResponseException(sprintf('%s: ожидалась строка, получен %s', $field, get_debug_type($value)));
        }

        return $value;
    }

    private static function date(mixed $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $invalid = new UnexpectedResponseException(sprintf('%s: не дата «Y-m-d» или ISO 8601', $field));
        if (!is_string($value) || preg_match(self::DATE_PATTERN, trim($value), $match) !== 1) {
            throw $invalid;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1], new \DateTimeZone(self::TIMEZONE));
        if ($day === false || $day->format('Y-m-d') !== $match[1]) {
            throw $invalid;
        }
        if (trim($value) === $match[1]) {
            return $day;
        }
        try {
            return new \DateTimeImmutable(trim($value), new \DateTimeZone(self::TIMEZONE));
        } catch (\Exception) {
            throw $invalid;
        }
    }

    private static function day(mixed $value): ?\DateTimeImmutable
    {
        $moment = self::date($value, 'order_date');
        if ($moment === null) {
            return null;
        }
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $moment->setTimezone($timezone)->format('Y-m-d'), $timezone);

        return $day === false ? null : $day;
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? sprintf('«%s»', preg_match('/^.{0,40}/us', (string) $value, $m) === 1 ? $m[0] : substr((string) $value, 0, 40))
            : get_debug_type($value);
    }
}
