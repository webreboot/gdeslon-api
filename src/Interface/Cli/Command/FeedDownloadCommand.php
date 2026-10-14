<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli\Command;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownload;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedValidators;
use Webreboot\GdeSlon\Interface\Cli\CommandContext;
use Webreboot\GdeSlon\Interface\Cli\Credentials;
use Webreboot\GdeSlon\Interface\Cli\Environment;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Cli\Input;
use Webreboot\GdeSlon\Interface\Cli\MissingCredentialsException;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\Output\Details;
use Webreboot\GdeSlon\Interface\Cli\UsageException;

/**
 * @internal
 */
final class FeedDownloadCommand extends BaseCommand
{
    public function name(): string
    {
        return 'feed download';
    }

    public function summary(): string
    {
        return 'скачать товарную выгрузку по ссылке из GDESLON_FEED_URLS (ключи API не нужны)';
    }

    public function help(): string
    {
        return "Ссылки — только из окружения (или --env-file): GDESLON_FEED_URLS, несколько — через пробел.\n"
            . "Загрузка идёт частями с докачкой; zip распаковывается в FILE. ETag и Last-Modified сохраняются\n"
            . 'в FILE.meta.json: следующий запуск спрашивает «изменилась ли выгрузка» и не качает её заново.';
    }

    public function options(): array
    {
        return [
            Option::value('feed', 'номер ссылки в GDESLON_FEED_URLS (по умолчанию 1)', 'N'),
            Option::value('output', 'файл выгрузки (по умолчанию gdeslon-feed-N.xml)', 'FILE'),
        ];
    }

    public function credentials(): Credentials
    {
        return Credentials::None;
    }

    public function execute(Input $input, array $arguments, CommandContext $context): int
    {
        self::noArguments($arguments);
        $number = self::intOption($input, 'feed') ?? 1;
        $urls = $context->environment->feedUrls();
        if ($urls === []) {
            throw new MissingCredentialsException(
                'Нет ссылки на выгрузку: создайте выгрузку в кабинете https://gdeslon.ru/export_files/ и задайте GDESLON_FEED_URLS (или --env-file)',
            );
        }
        if ($number > count($urls)) {
            throw new UsageException(sprintf('Нет ссылки --feed=%d: ссылок в %s: %d', $number, Environment::FEED_URLS, count($urls)));
        }
        try {
            $url = FeedUrl::fromString($urls[$number - 1]);
        } catch (InvalidArgumentException $e) {
            throw new UsageException(sprintf('%s, ссылка %d: %s', Environment::FEED_URLS, $number, $e->getMessage()));
        }
        $target = $input->value('output') ?? sprintf('gdeslon-feed-%d.xml', $number);
        $metaPath = $target . '.meta.json';

        $download = $context->gdeslon()->downloadFeed($url, $target, self::previous($metaPath));
        @file_put_contents($metaPath, json_encode($download->validators()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        if ($context->json) {
            $context->json(self::toArray($download));

            return ExitCode::OK;
        }
        $context->text(Details::render([
            [$download->isNotModified() ? 'Не изменилась' : 'Скачано', $download->path()],
            ['Размер', number_format($download->bytes(), 0, ',', ' ') . ' байт'],
            ['ETag', $download->validators()->etag()],
            ['Last-Modified', $download->validators()->lastModified()],
        ]));

        return ExitCode::OK;
    }

    private static function previous(string $metaPath): ?FeedValidators
    {
        $json = is_file($metaPath) ? @file_get_contents($metaPath) : false;
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        try {
            return FeedValidators::fromArray($data);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(FeedDownload $download): array
    {
        return [
            'status' => $download->status()->value,
            'path' => $download->path(),
            'bytes' => $download->bytes(),
            'etag' => $download->validators()->etag(),
            'last_modified' => $download->validators()->lastModified(),
        ];
    }
}
