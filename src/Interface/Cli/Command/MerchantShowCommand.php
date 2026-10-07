<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Catalog\MerchantCategory;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Output\Details;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;

/**
 * @internal
 */
final class MerchantShowCommand extends BaseCommand
{
    public function name(): string
    {
        return 'merchants show';
    }

    public function arguments(): string
    {
        return '<ID>';
    }

    public function summary(): string
    {
        return 'магазин: условия, тарифы, партнёрская ссылка';
    }

    public function credentials(): Credentials
    {
        return Credentials::TokenOptional;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        $id = self::idArgument($arguments, 'ID магазина');
        $merchant = $context->gdeslon()->merchants()->find($id);
        if ($merchant === null) {
            $context->notice(sprintf('Магазина %d нет', $id));

            return ExitCode::FAILURE;
        }

        if ($context->json) {
            $context->json(['merchant' => CatalogNormalizer::merchant($merchant)]);

            return ExitCode::OK;
        }
        $traffic = static fn (array $names): ?string => $names === [] ? null : implode(', ', $names);
        $context->text(Details::render([
            ['ID', (string) $merchant->id()->value()],
            ['Магазин', $merchant->name()],
            ['Сайт', $merchant->url()],
            ['Страна', $merchant->country()],
            ['Вознаграждение', $merchant->commissionSummary()],
            ['Категории', implode(', ', array_map(static fn (MerchantCategory $c): string => $c->name() ?? (string) $c->id(), $merchant->categories()))],
            ['Трафик разрешён', $traffic($merchant->allowedTrafficTypes())],
            ['Трафик запрещён', $traffic($merchant->forbiddenTrafficTypes())],
            ['Описание', $merchant->shortDescription()],
            ['Условия', $merchant->conditions()],
            ['Партнёрская ссылка', $merchant->affiliateLink() ?? 'нет (задайте GDESLON_API_TOKEN)'],
            ['Маркировка рекламы', $merchant->adMarking()],
        ]));

        return ExitCode::OK;
    }
}
