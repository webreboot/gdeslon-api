<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Testing;

use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownload;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownloader;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownloadStatus;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedValidators;

final class FakeFeedDownloader implements FeedDownloader
{
    /** @var list<array{string, FeedValidators}|GdeSlonException|null> */
    private array $queue = [];

    /** @var list<array{url: string, target: string, previous: ?FeedValidators}> */
    private array $downloads = [];

    public function willDownload(#[\SensitiveParameter] string $xml, ?FeedValidators $validators = null): self
    {
        $this->queue[] = [$xml, $validators ?? new FeedValidators(null, null)];

        return $this;
    }

    public function willReturnNotModified(): self
    {
        $this->queue[] = null;

        return $this;
    }

    public function willThrow(GdeSlonException $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    /**
     * @throws GdeSlonException
     * @throws \LogicException
     */
    public function download(#[\SensitiveParameter] FeedUrl $url, string $target, ?FeedValidators $previous = null): FeedDownload
    {
        $this->downloads[] = ['url' => $url->masked(), 'target' => $target, 'previous' => $previous];
        if ($this->queue === []) {
            throw new \LogicException('FakeFeedDownloader: неожиданная загрузка выгрузки');
        }
        $next = array_shift($this->queue);
        if ($next instanceof GdeSlonException) {
            throw $next;
        }
        if ($next === null) {
            clearstatcache(true, $target);

            return new FeedDownload($target, FeedDownloadStatus::NotModified, is_file($target) ? (int) filesize($target) : 0, $previous ?? new FeedValidators(null, null));
        }

        [$xml, $validators] = $next;
        file_put_contents($target, $xml);

        return new FeedDownload($target, FeedDownloadStatus::Downloaded, strlen($xml), $validators);
    }

    /**
     * @return list<array{url: string, target: string, previous: ?FeedValidators}>
     */
    public function downloads(): array
    {
        return $this->downloads;
    }
}
