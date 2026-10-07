<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Output\Details;
use Webreboot\GdeSlon\Interface\Normalizer\ClaimsNormalizer;

/**
 * @internal
 */
final class LostOrderShowCommand extends BaseCommand
{
    public function name(): string
    {
        return 'lost-orders show';
    }

    public function arguments(): string
    {
        return '<ID>';
    }

    public function summary(): string
    {
        return 'заявка на потерянный заказ';
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        $id = self::idArgument($arguments, 'ID заявки');
        $claim = $context->gdeslon()->lostOrder($id);
        if ($claim === null) {
            $context->notice(sprintf('Заявки %d нет', $id));

            return ExitCode::FAILURE;
        }

        if ($context->json) {
            $context->json(['claim' => ClaimsNormalizer::claim($claim)]);
        } else {
            $context->text(Details::render(ClaimLabels::details($claim)));
        }

        return ExitCode::OK;
    }
}
