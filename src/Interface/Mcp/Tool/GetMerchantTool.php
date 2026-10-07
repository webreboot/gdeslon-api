<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\NotFoundException;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\CatalogNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;

/**
 * @internal
 */
final class GetMerchantTool extends ReadTool
{
    public function name(): string
    {
        return 'get_merchant';
    }

    public function title(): string
    {
        return 'Магазин';
    }

    public function description(): string
    {
        return 'Полная карточка магазина: описание, условия, тарифы, разрешённый трафик, партнёрская ссылка, маркировка рекламы.';
    }

    public function inputProperties(): array
    {
        return ['merchant_id' => self::id('ID магазина')];
    }

    public function required(): array
    {
        return ['merchant_id'];
    }

    public function outputShape(): array
    {
        return ['merchant' => JsonShapes::MERCHANT];
    }

    public function credentials(): Credentials
    {
        return Credentials::TokenOptional;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $id = $arguments->int('merchant_id', 1);
        if ($id === null) {
            $arguments->reject('merchant_id обязателен');
        }
        $arguments->done();

        $merchant = $context->gdeslon()->merchants()->find((int) $id) ?? throw new NotFoundException(sprintf('Магазина %d нет', $id));

        return ['merchant' => CatalogNormalizer::merchant($merchant)];
    }
}
