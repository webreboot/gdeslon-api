<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\Json;
use Webreboot\GdeSlon\Interface\Mcp\Page;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;
use Webreboot\GdeSlon\Interface\Normalizer\SalesNormalizer;

/**
 * @internal
 */
final class ListOrdersTool extends ReadTool
{
    public const DATE_FIELDS = [
        'created' => OrderDateField::Created,
        'transition' => OrderDateField::Transition,
        'last_updated' => OrderDateField::LastUpdated,
        'confirmed' => OrderDateField::Confirmed,
        'accrued' => OrderDateField::Accrued,
    ];

    public const STATES = [
        'new' => OrderState::New,
        'cancelled' => OrderState::Cancelled,
        'pending' => OrderState::Pending,
        'confirmed' => OrderState::Confirmed,
        'paid' => OrderState::Paid,
    ];

    public const TYPES = ['product' => OrderType::Product, 'lead' => OrderType::Lead];

    public function name(): string
    {
        return 'list_orders';
    }

    public function title(): string
    {
        return 'Заказы';
    }

    public function description(): string
    {
        return 'Заказы (продажи) вебмастера за период: по умолчанию созданные за 30 дней по московскому «сегодня». Запрос идёт '
            . '4–6 с, весь период приходит одним ответом; статус обновляется в API на следующий день. Нужны GDESLON_USER_ID и '
            . 'GDESLON_API_KEY.';
    }

    public function inputProperties(): array
    {
        return [
            'date_field' => self::enum('по какой дате период (по умолчанию created)', array_keys(self::DATE_FIELDS)),
            'until' => self::date('последний день периода, по умолчанию сегодня по Москве'),
            'days' => self::int('длина периода в днях', 1, OrderCriteria::MAX_DAYS, OrderCriteria::DEFAULT_DAYS),
            'merchant_id' => self::id('один магазин'),
            'states' => Json::object(['type' => 'array', 'items' => self::enum('статус', array_keys(self::STATES)), 'maxItems' => 5, 'description' => 'статусы (любой из)']),
            'type' => self::enum('тип заказа', array_keys(self::TYPES)),
            'sub_id' => self::text('sub_id из партнёрской ссылки', 255),
            ...Page::inputSchema(50, 200),
        ];
    }

    public function outputShape(): array
    {
        return ['orders' => ['[]' => JsonShapes::ORDER], 'skipped' => ['[]' => 'string']] + self::PAGE_SHAPE;
    }

    public function credentials(): Credentials
    {
        return Credentials::SalesKeys;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $dateField = $arguments->choice('date_field', self::DATE_FIELDS);
        $until = $arguments->date('until');
        $days = $arguments->int('days', 1, OrderCriteria::MAX_DAYS);
        $merchant = $arguments->int('merchant_id', 1);
        $states = $arguments->choiceList('states', self::STATES);
        $type = $arguments->choice('type', self::TYPES);
        $subId = $arguments->string('sub_id', 255);
        $page = Page::of($arguments, 50, 200);
        $arguments->done();

        $criteria = new OrderCriteria(
            dateField: $dateField ?? OrderDateField::Created,
            until: $until ?? $context->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'),
            days: $days ?? OrderCriteria::DEFAULT_DAYS,
            merchant: $merchant,
            states: $states,
            type: $type,
            subId: $subId,
        );
        $orders = $context->gdeslon()->orders($criteria);

        return [
            'orders' => array_map(SalesNormalizer::order(...), $page->slice($orders->all())),
            'skipped' => $orders->skipped(),
        ] + $page->meta($orders->count());
    }
}
