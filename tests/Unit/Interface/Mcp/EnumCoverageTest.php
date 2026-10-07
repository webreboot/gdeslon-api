<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Mcp;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Domain\Sales\OrderDateField;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Sales\OrderType;
use Webreboot\GdeSlon\Interface\Mcp\Tool\ListLostOrderClaimsTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\ListOrdersTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\SearchOffersTool;

/**
 * Новый case в домене должен стать значением аргумента MCP, а не остаться недоступным молча.
 */
final class EnumCoverageTest extends TestCase
{
    public function testEveryCaseHasArgumentValue(): void
    {
        foreach ([
            [OrderDateField::cases(), ListOrdersTool::DATE_FIELDS],
            [OrderState::cases(), ListOrdersTool::STATES],
            [OrderType::cases(), ListOrdersTool::TYPES],
            [OfferSort::cases(), SearchOffersTool::SORTS],
            [LostOrderClaimState::cases(), ListLostOrderClaimsTool::CLAIM_STATES],
            [LostOrderStatus::cases(), ListLostOrderClaimsTool::ORDER_STATUSES],
        ] as [$cases, $values]) {
            self::assertCount(count($cases), $values);
            foreach ($cases as $case) {
                self::assertContains($case, $values, $case::class . '::' . $case->name);
            }
            foreach (array_keys($values) as $value) {
                self::assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*\z/', $value, 'значения — snake_case');
            }
        }
    }
}
