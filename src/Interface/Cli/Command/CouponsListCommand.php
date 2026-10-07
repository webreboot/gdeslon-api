<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Normalizer\PromoNormalizer;

/**
 * @internal
 */
final class CouponsListCommand extends BaseCommand
{
    public function name(): string
    {
        return 'coupons list';
    }

    public function summary(): string
    {
        return 'купоны и промокоды ваших магазинов («coupons» — то же)';
    }

    public function help(): string
    {
        return "Партнёрские ссылки купонов содержат ваш токен XML API, поэтому в выводе он заменён на ***.\n"
            . 'Настоящие ссылки — с --reveal-links (не публикуйте их как есть). Ссылки — в --format=json и coupons show.';
    }

    public function options(): array
    {
        return [
            Option::listOf('merchant', 'только эти магазины'),
            Option::listOf('kind', 'только эти виды (gdeslon coupons kinds)'),
            Option::flag('active', 'только действующие сейчас'),
            Option::flag('reveal-links', 'настоящие ссылки с токеном'),
        ];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        $criteria = new CouponCriteria(self::intList($input, 'merchant'), self::intList($input, 'kind'));
        $reveal = self::revealLinks($input, $context);

        $list = $context->gdeslon()->coupons($criteria);
        $coupons = $input->flag('active') ? $list->activeAt($context->clock->now()) : $list->all();
        $context->warnSkipped($list->skipped());
        if ($context->json) {
            $context->json((new PromoNormalizer($reveal))->couponList($list, $coupons));

            return ExitCode::OK;
        }
        if ($coupons === []) {
            $context->nothingFound();

            return ExitCode::OK;
        }
        $context->text(Table::render(['ID', 'Магазин', 'Промокод', 'Вид', 'До (МСК)', 'Название'], array_map(static fn (Coupon $coupon): array => [
            (string) $coupon->id()->value(),
            $coupon->merchantName() ?? (string) $coupon->merchantId()->value(),
            $coupon->code(),
            $coupon->kind()->name(),
            CommandContext::moscow($coupon->endsAt(), 'Y-m-d'),
            $coupon->name(),
        ], $coupons), [1 => 25, 2 => 25, 3 => 20, 5 => 60]));

        return ExitCode::OK;
    }

    /**
     * --reveal-links: снимает маску токена в stdout и предупреждает в stderr.
     */
    public static function revealLinks(Input $input, CommandContext $context): bool
    {
        if (!$input->flag('reveal-links')) {
            return false;
        }
        $context->console->revealStdout();
        $context->notice('Внимание: ссылки купонов содержат ваш токен XML API — не публикуйте и не пересылайте их как есть.');

        return true;
    }
}
