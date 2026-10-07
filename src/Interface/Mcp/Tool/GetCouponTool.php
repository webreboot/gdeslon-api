<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\NotFoundException;
use Webreboot\GdeSlon\Interface\Mcp\Protocol;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;
use Webreboot\GdeSlon\Interface\Normalizer\PromoNormalizer;

/**
 * @internal
 */
final class GetCouponTool extends ReadTool
{
    public function __construct(private readonly bool $revealLinks = false)
    {
    }

    public function name(): string
    {
        return 'get_coupon';
    }

    public function title(): string
    {
        return 'Купон';
    }

    public function description(): string
    {
        return 'Купон по ID: условия, промокод, сроки, ссылки, маркировка рекламы. '
            . ($this->revealLinks ? Protocol::LINKS_REVEALED : 'В ссылках токен скрыт (/ck/***/).') . ' Нужен GDESLON_API_TOKEN.';
    }

    public function inputProperties(): array
    {
        return ['coupon_id' => self::id('ID купона')];
    }

    public function required(): array
    {
        return ['coupon_id'];
    }

    public function outputShape(): array
    {
        return ['coupon' => JsonShapes::COUPON];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function exposesToken(): bool
    {
        return true;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $id = $arguments->int('coupon_id', 1);
        if ($id === null) {
            $arguments->reject('coupon_id обязателен');
        }
        $arguments->done();

        $coupon = $context->gdeslon()->coupons()->find((int) $id) ?? throw new NotFoundException(sprintf('Купона %d нет', $id));

        return ['coupon' => (new PromoNormalizer($context->revealLinks))->coupon($coupon)];
    }
}
