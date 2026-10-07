<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\Page;
use Webreboot\GdeSlon\Interface\Mcp\Protocol;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;
use Webreboot\GdeSlon\Interface\Normalizer\PromoNormalizer;

/**
 * @internal
 */
final class ListCouponsTool extends ReadTool
{
    public function __construct(private readonly bool $revealLinks = false)
    {
    }

    public function name(): string
    {
        return 'list_coupons';
    }

    public function title(): string
    {
        return 'Купоны и промокоды';
    }

    public function description(): string
    {
        return 'Купоны и промокоды магазинов вебмастера с маркировкой рекламы (ad_marking — показывайте рядом со ссылкой). '
            . ($this->revealLinks ? Protocol::LINKS_REVEALED : 'В ссылках токен скрыт (/ck/***/).')
            . ' kinds — справочник видов для kind_ids. Нужен GDESLON_API_TOKEN.';
    }

    public function inputProperties(): array
    {
        return [
            'merchant_ids' => self::ids('только эти магазины (подключённые вебмастеру)'),
            'kind_ids' => self::ids('только эти виды (list_coupon_kinds)'),
            'active_only' => self::flag('только действующие сейчас'),
            ...Page::inputSchema(20, 100),
        ];
    }

    public function outputShape(): array
    {
        return ['coupons' => ['[]' => JsonShapes::COUPON], 'kinds' => ['[]' => JsonShapes::COUPON_KIND], 'skipped' => ['[]' => 'string']] + self::PAGE_SHAPE;
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
        $merchants = $arguments->intList('merchant_ids');
        $kinds = $arguments->intList('kind_ids');
        $activeOnly = $arguments->bool('active_only') ?? false;
        $page = Page::of($arguments, 20, 100);
        $arguments->done();

        $list = $context->gdeslon()->coupons(new CouponCriteria($merchants, $kinds));
        $coupons = $activeOnly ? $list->activeAt($context->clock->now()) : $list->all();
        $data = (new PromoNormalizer($context->revealLinks))->couponList($list, $page->slice($coupons));

        return $data + $page->meta(count($coupons));
    }
}
