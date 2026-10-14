<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

final class FeedDownload
{
    public function __construct(
        private readonly string $path,
        private readonly FeedDownloadStatus $status,
        private readonly int $bytes,
        private readonly FeedValidators $validators,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function status(): FeedDownloadStatus
    {
        return $this->status;
    }

    public function isNotModified(): bool
    {
        return $this->status === FeedDownloadStatus::NotModified;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function validators(): FeedValidators
    {
        return $this->validators;
    }
}
