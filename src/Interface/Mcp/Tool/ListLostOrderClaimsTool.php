<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\Page;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\ClaimsNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;

/**
 * @internal
 */
final class ListLostOrderClaimsTool extends ReadTool
{
    /** Значения аргумента → домен (EnumCoverageTest). */
    public const CLAIM_STATES = ['in_work' => LostOrderClaimState::InWork, 'closed' => LostOrderClaimState::Closed];

    /** Значения аргумента → домен (EnumCoverageTest). */
    public const ORDER_STATUSES = [
        'waiting' => LostOrderStatus::Waiting,
        'confirmed' => LostOrderStatus::Confirmed,
        'declined' => LostOrderStatus::Declined,
    ];

    public function name(): string
    {
        return 'list_lost_order_claims';
    }

    public function title(): string
    {
        return 'Заявки на потерянные заказы';
    }

    public function description(): string
    {
        return 'Заявки вебмастера на потерянные заказы и их статусы. Только чтение: создать заявку — gdeslon lost-orders submit '
            . 'в терминале. Нужен GDESLON_API_TOKEN.';
    }

    public function inputProperties(): array
    {
        return [
            'merchant_id' => self::id('магазин'),
            'from' => self::date('с дня'),
            'until' => self::date('по день'),
            'claim_state' => self::enum('состояние заявки', array_keys(self::CLAIM_STATES)),
            'order_status' => self::enum('статус заказа', array_keys(self::ORDER_STATUSES)),
            ...Page::inputSchema(50, 200),
        ];
    }

    public function outputShape(): array
    {
        return ['claims' => ['[]' => JsonShapes::CLAIM], 'skipped' => ['[]' => 'string']] + self::PAGE_SHAPE;
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $merchant = $arguments->int('merchant_id', 1);
        $from = $arguments->date('from');
        $until = $arguments->date('until');
        $claimState = $arguments->choice('claim_state', self::CLAIM_STATES);
        $orderStatus = $arguments->choice('order_status', self::ORDER_STATUSES);
        $page = Page::of($arguments, 50, 200);
        $arguments->done();

        $claims = $context->gdeslon()->lostOrders(new LostOrderCriteria($merchant, $from, $until, $claimState, $orderStatus));

        return [
            'claims' => array_map(ClaimsNormalizer::claim(...), $page->slice($claims->all())),
            'skipped' => $claims->skipped(),
        ] + $page->meta($claims->count());
    }
}
