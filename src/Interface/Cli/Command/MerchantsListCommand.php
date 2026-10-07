<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\Output\Table;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;

/**
 * @internal
 */
final class MerchantsListCommand extends BaseCommand
{
    public function name(): string
    {
        return 'merchants list';
    }

    public function summary(): string
    {
        return 'магазины (с токеном — ваши, с партнёрскими ссылками; «merchants» — то же)';
    }

    public function help(): string
    {
        return "Без GDESLON_API_TOKEN — публичный каталог без партнёрских ссылок. Фильтры складываются (И).";
    }

    public function options(): array
    {
        return [
            Option::value('search', 'подстрока в названии или домене', 'TEXT'),
            Option::value('domain', 'сайт магазина: хост или URL', 'HOST'),
            Option::value('category', 'ID категории магазинов (gdeslon merchants categories)', 'ID'),
        ];
    }

    public function credentials(): Credentials
    {
        return Credentials::TokenOptional;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        $category = self::intOption($input, 'category');

        $list = $context->gdeslon()->merchants();
        $merchants = $list->all();
        if ($input->value('search') !== null) {
            $merchants = self::intersect($merchants, $list->search($input->value('search')));
        }
        if ($input->value('domain') !== null) {
            $merchants = self::intersect($merchants, $list->findByDomain($input->value('domain')));
        }
        if ($category !== null) {
            $merchants = self::intersect($merchants, $list->inCategory($category));
        }

        $context->warnSkipped($list->skipped());
        if ($context->json) {
            $context->json(['merchants' => array_map(CatalogNormalizer::merchant(...), $merchants), 'skipped' => $list->skipped()]);

            return ExitCode::OK;
        }
        if ($merchants === []) {
            $context->nothingFound();

            return ExitCode::OK;
        }
        $context->text(Table::render(['ID', 'Магазин', 'Домен', 'Вознаграждение'], array_map(static fn (Merchant $merchant): array => [
            (string) $merchant->id()->value(),
            $merchant->name(),
            $merchant->domain(),
            $merchant->commissionSummary(),
        ], $merchants), [1 => 40, 3 => 40]));

        return ExitCode::OK;
    }

    /**
     * @param list<Merchant> $merchants
     * @param list<Merchant> $matching
     *
     * @return list<Merchant>
     */
    private static function intersect(array $merchants, array $matching): array
    {
        $ids = array_map(static fn (Merchant $merchant): int => $merchant->id()->value(), $matching);

        return array_values(array_filter($merchants, static fn (Merchant $merchant): bool => in_array($merchant->id()->value(), $ids, true)));
    }
}
