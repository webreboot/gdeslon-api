<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;
use Webreboot\GdeSlon\Interface\Normalizer\PromoNormalizer;

/**
 * @internal
 */
final class ListCouponKindsTool extends ReadTool
{
    public function name(): string
    {
        return 'list_coupon_kinds';
    }

    public function title(): string
    {
        return 'Виды купонов';
    }

    public function description(): string
    {
        return 'Виды купонов (скидка, бесплатная доставка, подарок…) — ID для list_coupons.kind_ids. Нужен GDESLON_API_TOKEN.';
    }

    public function outputShape(): array
    {
        return ['kinds' => ['[]' => JsonShapes::COUPON_KIND]];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $arguments->done();

        return ['kinds' => array_map(PromoNormalizer::kind(...), $context->gdeslon()->coupons()->kinds())];
    }
}
