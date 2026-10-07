<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp\Tool;

use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\NotFoundException;
use Webreboot\GdeSlon\Interface\Mcp\ToolContext;
use Webreboot\GdeSlon\Interface\Normalizer\ClaimsNormalizer;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;

/**
 * @internal
 */
final class GetLostOrderClaimTool extends ReadTool
{
    public function name(): string
    {
        return 'get_lost_order_claim';
    }

    public function title(): string
    {
        return 'Заявка на потерянный заказ';
    }

    public function description(): string
    {
        return 'Заявка на потерянный заказ по ID. Нужен GDESLON_API_TOKEN.';
    }

    public function inputProperties(): array
    {
        return ['claim_id' => self::id('ID заявки')];
    }

    public function required(): array
    {
        return ['claim_id'];
    }

    public function outputShape(): array
    {
        return ['claim' => JsonShapes::CLAIM];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function call(Arguments $arguments, ToolContext $context): array
    {
        $id = $arguments->int('claim_id', 1);
        if ($id === null) {
            $arguments->reject('claim_id обязателен');
        }
        $arguments->done();

        $claim = $context->gdeslon()->lostOrder((int) $id) ?? throw new NotFoundException(sprintf('Заявки %d нет', $id));

        return ['claim' => ClaimsNormalizer::claim($claim)];
    }
}
