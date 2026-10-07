<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\OptionValues;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Normalizer\SalesNormalizer;

/**
 * @internal
 */
final class OrdersCommand extends BaseCommand
{
    private const DATE_FIELDS = [
        'created' => OrderDateField::Created,
        'transition' => OrderDateField::Transition,
        'last-updated' => OrderDateField::LastUpdated,
        'confirmed' => OrderDateField::Confirmed,
        'accrued' => OrderDateField::Accrued,
    ];

    private const STATES = [
        'new' => OrderState::New,
        'cancelled' => OrderState::Cancelled,
        'pending' => OrderState::Pending,
        'confirmed' => OrderState::Confirmed,
        'paid' => OrderState::Paid,
    ];

    private const TYPES = ['product' => OrderType::Product, 'lead' => OrderType::Lead];

    public function name(): string
    {
        return 'orders';
    }

    public function summary(): string
    {
        return 'заказы (продажи) вебмастера';
    }

    public function help(): string
    {
        return "По умолчанию — созданные за 30 дней по московскому «сегодня». Запрос идёт 4–6 с, статус обновляется\n"
            . 'в API на следующий день. Нужны GDESLON_USER_ID и GDESLON_API_KEY.';
    }

    public function options(): array
    {
        return [
            Option::value('date-field', 'по какой дате период: created (по умолчанию), transition, last-updated, confirmed, accrued', 'ПОЛЕ'),
            Option::value('until', 'последний день периода (по умолчанию сегодня по Москве)', 'ГГГГ-ММ-ДД'),
            Option::value('days', 'длина периода в днях (по умолчанию 30)', 'N'),
            Option::value('merchant', 'один магазин', 'ID'),
            Option::listOf('state', 'статусы: new, cancelled, pending, confirmed, paid', 'СТАТУС,…'),
            Option::value('type', 'тип: product или lead', 'ТИП'),
            Option::value('sub-id', 'sub_id из партнёрской ссылки', 'SUBID'),
        ];
    }

    public function credentials(): Credentials
    {
        return Credentials::SalesKeys;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        $dateField = $input->value('date-field');
        $type = $input->value('type');
        $criteria = new OrderCriteria(
            dateField: $dateField === null ? OrderDateField::Created : OptionValues::choice($dateField, self::DATE_FIELDS, 'date-field'),
            until: $input->value('until') ?? CommandContext::moscow($context->clock->now(), 'Y-m-d'),
            days: self::intOption($input, 'days') ?? OrderCriteria::DEFAULT_DAYS,
            merchant: self::intOption($input, 'merchant'),
            states: array_map(static fn (string $state): OrderState => OptionValues::choice($state, self::STATES, 'state'), $input->list('state')),
            type: $type === null ? null : OptionValues::choice($type, self::TYPES, 'type'),
            subId: $input->value('sub-id'),
        );

        $orders = $context->gdeslon()->orders($criteria);
        $context->warnSkipped($orders->skipped());
        if ($context->json) {
            $context->json(SalesNormalizer::orderList($orders));

            return ExitCode::OK;
        }
        if ($orders->isEmpty()) {
            $context->nothingFound();

            return ExitCode::OK;
        }

        $field = $criteria->dateField();
        $context->text(Table::render(['ID', 'Дата (МСК)', 'Магазин', 'Статус', 'Сумма', 'Вознаграждение', 'sub_id'], array_map(static fn (Order $order): array => [
            $order->id()->value(),
            CommandContext::moscow($order->date($field)),
            $order->merchantName() ?? (string) $order->merchantId()->value(),
            self::stateLabel($order->state()) . ($order->isLead() ? ' (лид)' : ''),
            $order->amount() === null ? null : (string) $order->amount(),
            (string) $order->reward(),
            $order->subId(),
        ], $orders->all()), [2 => 30, 6 => 30]));
        $context->text(sprintf("\nЗаказов: %d\n", $orders->count()));

        return ExitCode::OK;
    }

    private static function stateLabel(OrderState $state): string
    {
        return match ($state) {
            OrderState::New => 'новый',
            OrderState::Cancelled => 'отменён',
            OrderState::Pending => 'отложен',
            OrderState::Confirmed => 'подтверждён',
            OrderState::Paid => 'выплачен',
        };
    }
}
