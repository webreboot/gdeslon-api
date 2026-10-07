<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Promo\CouponKind;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Normalizer\PromoNormalizer;

/**
 * @internal
 */
final class CouponKindsCommand extends BaseCommand
{
    public function name(): string
    {
        return 'coupons kinds';
    }

    public function summary(): string
    {
        return 'виды купонов (для coupons --kind)';
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        $kinds = $context->gdeslon()->coupons()->kinds();

        if ($context->json) {
            $context->json(['kinds' => array_map(PromoNormalizer::kind(...), $kinds)]);

            return ExitCode::OK;
        }
        if ($kinds === []) {
            $context->nothingFound();

            return ExitCode::OK;
        }
        $context->text(Table::render(['ID', 'Вид'], array_map(
            static fn (CouponKind $kind): array => [$kind->id() === null ? null : (string) $kind->id(), $kind->name()],
            $kinds,
        )));

        return ExitCode::OK;
    }
}
