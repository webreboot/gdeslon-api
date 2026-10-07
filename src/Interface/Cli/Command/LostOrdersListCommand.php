<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\OptionValues;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Normalizer\ClaimsNormalizer;

/**
 * @internal
 */
final class LostOrdersListCommand extends BaseCommand
{
    private const CLAIM_STATES = ['in-work' => LostOrderClaimState::InWork, 'closed' => LostOrderClaimState::Closed];

    private const ORDER_STATUSES = [
        'waiting' => LostOrderStatus::Waiting,
        'confirmed' => LostOrderStatus::Confirmed,
        'declined' => LostOrderStatus::Declined,
    ];

    public function name(): string
    {
        return 'lost-orders list';
    }

    public function summary(): string
    {
        return 'заявки на потерянные заказы («lost-orders» — то же)';
    }

    public function options(): array
    {
        return [
            Option::value('merchant', 'магазин', 'ID'),
            Option::value('from', 'с дня', 'ГГГГ-ММ-ДД'),
            Option::value('until', 'по день', 'ГГГГ-ММ-ДД'),
            Option::value('claim-state', 'заявка: in-work или closed', 'СТАТУС'),
            Option::value('order-status', 'заказ: waiting, confirmed, declined', 'СТАТУС'),
        ];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        $claimState = $input->value('claim-state');
        $orderStatus = $input->value('order-status');
        $criteria = new LostOrderCriteria(
            merchant: self::intOption($input, 'merchant'),
            from: $input->value('from'),
            until: $input->value('until'),
            claimState: $claimState === null ? null : OptionValues::choice($claimState, self::CLAIM_STATES, 'claim-state'),
            orderStatus: $orderStatus === null ? null : OptionValues::choice($orderStatus, self::ORDER_STATUSES, 'order-status'),
        );

        $claims = $context->gdeslon()->lostOrders($criteria);
        $context->warnSkipped($claims->skipped());
        if ($context->json) {
            $context->json(ClaimsNormalizer::claimList($claims));

            return ExitCode::OK;
        }
        if ($claims->isEmpty()) {
            $context->nothingFound();

            return ExitCode::OK;
        }
        $context->text(Table::render(['ID', 'Заказ', 'Дата', 'Сумма', 'Магазин', 'Статус заказа', 'Заявка'], array_map(static fn (LostOrderClaim $claim): array => [
            (string) $claim->id()->value(),
            $claim->orderNumber(),
            $claim->orderDate()->format('Y-m-d'),
            $claim->orderTotal()->amount(),
            $claim->merchantName() ?? (string) $claim->merchantId()->value(),
            ClaimLabels::orderStatus($claim->orderStatus()),
            ClaimLabels::claimState($claim->claimState()),
        ], $claims->all()), [1 => 30, 4 => 30]));

        return ExitCode::OK;
    }
}
