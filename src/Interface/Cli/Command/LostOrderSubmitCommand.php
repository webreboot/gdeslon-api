<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Infrastructure\Claims\AttachmentFile;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\ErrorReporter;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\OutputFailedException;
use Webreboot\GdeSlon\Interface\Cli\Output\Details;
use Webreboot\GdeSlon\Interface\Cli\UsageException;
use Webreboot\GdeSlon\Interface\Normalizer\ClaimsNormalizer;

/**
 * Создание РЕАЛЬНОЙ заявки у рекламодателя. Всё, что можно проверить без сети, проверяется до первого запроса;
 * отправка — только после ввода «yes» (или --yes). Запрос не повторяется.
 *
 * @internal
 */
final class LostOrderSubmitCommand extends BaseCommand
{
    private const REQUIRED = ['merchant', 'order-number', 'order-date', 'order-total', 'attachment'];

    public function name(): string
    {
        return 'lost-orders submit';
    }

    public function summary(): string
    {
        return 'создать РЕАЛЬНУЮ заявку на потерянный заказ';
    }

    public function help(): string
    {
        return "Заявка уходит рекламодателю — это не тест. Перед отправкой команда проверяет дату (не старше 3 месяцев),\n"
            . "сумму, чек (JPEG/PNG/PDF до 10 МиБ) и ищет заявку на тот же заказ; затем просит ввести yes.\n"
            . "--dry-run — только проверки, без отправки. Код 4 — исход неизвестен: НЕ повторяйте, а через несколько\n"
            . "минут проверьте gdeslon lost-orders list --merchant=<ID>. Код 5 — заявка на этот заказ уже есть.\n"
            . 'Параллельные запуски от дублей не защищены — отправляйте заявки по одной. «echo yes |» равносильно --yes.';
    }

    public function options(): array
    {
        return [
            Option::value('merchant', 'ID магазина', 'ID'),
            Option::value('order-number', 'номер заказа у магазина', 'НОМЕР'),
            Option::value('order-date', 'дата заказа', 'ГГГГ-ММ-ДД'),
            Option::value('order-total', 'сумма заказа, до 2 знаков после точки', 'СУММА'),
            Option::value('attachment', 'скан чека: JPEG, PNG или PDF до 10 МиБ', 'ФАЙЛ'),
            Option::value('description', 'комментарий для рекламодателя', 'ТЕКСТ'),
            Option::flag('no-duplicate-check', 'не искать заявку на тот же заказ'),
            Option::flag('yes', 'отправить без вопроса'),
            Option::flag('dry-run', 'только проверить, не отправлять'),
        ];
    }

    public function credentials(): Credentials
    {
        return Credentials::Token;
    }

    public function defaultTimeout(): float
    {
        return 120.0;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        $missing = array_values(array_filter(self::REQUIRED, static fn (string $name): bool => $input->value($name) === null));
        if ($missing !== []) {
            throw new UsageException('Не хватает опций: ' . implode(', ', array_map(static fn (string $name): string => '--' . $name, $missing)));
        }
        if ($input->flag('yes') && $input->flag('dry-run')) {
            throw new UsageException('«--yes» и «--dry-run» вместе не указываются');
        }
        $merchant = (int) self::intOption($input, 'merchant');
        $checkDuplicates = !$input->flag('no-duplicate-check');

        $claim = new NewLostOrderClaim(
            (string) $input->value('order-number'),
            (string) $input->value('order-date'),
            (string) $input->value('order-total'),
            $merchant,
            AttachmentFile::load((string) $input->value('attachment')),
            $input->value('description'),
        );
        $claim->assertOrderDateWithin($context->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow')));

        if ($checkDuplicates && ($code = self::checkDuplicates($claim, $context)) !== null) {
            return $code;
        }

        $context->notice(sprintf(
            'Заявка: магазин %d, заказ «%s» от %s на %s, чек %s (%d байт)%s',
            $merchant,
            $claim->orderNumber(),
            $claim->orderDate(),
            $claim->orderTotal()->amount(),
            $claim->attachment()->fileName(),
            $claim->attachment()->size(),
            $checkDuplicates ? '; заявок на этот заказ нет' : '',
        ));

        if ($input->flag('dry-run')) {
            if ($context->json) {
                $context->json(['dry_run' => true, 'claim' => ClaimsNormalizer::newClaim($claim)]);
            } else {
                $context->notice('Проверки пройдены; заявка не отправлена (--dry-run).');
            }

            return ExitCode::OK;
        }

        if (!$input->flag('yes')) {
            $context->console->err('Будет создана РЕАЛЬНАЯ заявка у рекламодателя. Введите yes для отправки: ');
            $answer = $context->console->readLine();
            if ($answer === null || strtolower(trim($answer)) !== 'yes') {
                $context->notice(($answer === null ? "\n" : '') . 'Отменено, заявка не отправлена.');

                return ExitCode::FAILURE;
            }
        }

        // повторно перед POST: пока ждали «yes», заявка могла появиться (фасад проверку уже не повторяет)
        if ($checkDuplicates && ($code = self::checkDuplicates($claim, $context)) !== null) {
            return $code;
        }
        try {
            $created = $context->gdeslon()->submitLostOrderClaim($claim, false);
        } catch (LostOrderClaimUnconfirmedException $error) {
            [$code, $text] = ErrorReporter::report($error);

            return self::outcome($code, static function () use ($context, $text, $merchant): void {
                $context->console->err($text);
                $context->notice(sprintf('Через несколько минут проверьте: gdeslon lost-orders list --merchant=%d', $merchant));
            });
        }

        return self::outcome(ExitCode::OK, static function () use ($context, $created): void {
            if ($context->json) {
                $context->json(['claim' => ClaimsNormalizer::claim($created)]);
            } else {
                $context->text(sprintf("Заявка создана: ID %d\n\n", $created->id()->value()) . Details::render(ClaimLabels::details($created)));
            }
        });
    }

    /**
     * Исход заявки уже известен: сбой вывода (полный диск, закрытый поток) не меняет код — иначе 4 «не повторять» или
     * 0 «создана» превратились бы в 1, который скрипты повторяют.
     *
     * @param \Closure(): void $report
     */
    private static function outcome(int $code, \Closure $report): int
    {
        try {
            $report();
        } catch (OutputFailedException) {
            // отчёт не записан, код исхода важнее
        }

        return $code;
    }

    /**
     * Ищет заявку на тот же заказ этого магазина. Сообщения — на языке CLI (опции, а не параметры SDK).
     *
     * @return int|null код выхода, если отправлять нельзя
     */
    private static function checkDuplicates(NewLostOrderClaim $claim, CommandContext $context): ?int
    {
        $merchant = $claim->merchant()->value();
        $existing = $context->gdeslon()->lostOrders(new LostOrderCriteria(merchant: $merchant));
        if ($existing->skipped() !== []) {
            $context->notice(sprintf(
                "Не удалось проверить дубли: %d заявок магазина не разобраны (%s). Заявка не отправлена.\n"
                . 'Проверьте вручную (gdeslon lost-orders list --merchant=%d) и повторите с --no-duplicate-check.',
                count($existing->skipped()),
                $existing->skipped()[0],
                $merchant,
            ));

            return ExitCode::FAILURE;
        }
        $duplicate = $existing->findByOrderNumber($claim->merchant(), $claim->orderNumber());
        if ($duplicate === null) {
            return null;
        }
        [$code, $text] = ErrorReporter::report(new DuplicateLostOrderClaimException($duplicate));

        return self::outcome($code, static function () use ($context, $text, $duplicate): void {
            $context->console->err($text);
            $context->console->err(Details::render(ClaimLabels::details($duplicate)));
        });
    }
}
