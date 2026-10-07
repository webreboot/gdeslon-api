<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCategory;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\Output\Details;
use Webreboot\GdeSlon\Interface\Normalizer\PromoNormalizer;

/**
 * @internal
 */
final class CouponShowCommand extends BaseCommand
{
    public function name(): string
    {
        return 'coupons show';
    }

    public function arguments(): string
    {
        return '<ID>';
    }

    public function summary(): string
    {
        return 'купон: условия, ссылки, маркировка рекламы';
    }

    public function options(): array
    {
        return [Option::flag('reveal-links', 'настоящие ссылки с токеном')];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        $id = self::idArgument($arguments, 'ID купона');
        $reveal = CouponsListCommand::revealLinks($input, $context);
        $coupon = $context->gdeslon()->coupons()->find($id);
        if ($coupon === null) {
            $context->notice(sprintf('Купона %d нет', $id));

            return ExitCode::FAILURE;
        }

        if ($context->json) {
            $context->json(['coupon' => (new PromoNormalizer($reveal))->coupon($coupon)]);

            return ExitCode::OK;
        }
        $link = static fn (?string $link): ?string => $link === null || $reveal ? $link : Coupon::maskLink($link);
        $context->text(Details::render([
            ['ID', (string) $coupon->id()->value()],
            ['Магазин', trim(sprintf('%d %s', $coupon->merchantId()->value(), $coupon->merchantName() ?? ''))],
            ['Название', $coupon->name()],
            ['Промокод', $coupon->code()],
            ['Вид', $coupon->kind()->name()],
            ['Категории', implode(', ', array_map(static fn (CouponCategory $c): string => $c->name() ?? (string) $c->id()->value(), $coupon->categories()))],
            ['Действует (МСК)', sprintf('%s — %s', CommandContext::moscow($coupon->startsAt()), CommandContext::moscow($coupon->endsAt()))],
            ['Описание', $coupon->description()],
            ['Как применить', $coupon->instruction()],
            ['Ссылка', $link($coupon->affiliateLink())],
            ['Ссылка с промокодом', $link($coupon->affiliateLinkWithCode())],
            ['Маркировка рекламы', $coupon->adMarking()],
        ]));

        return ExitCode::OK;
    }
}
