<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

enum FeedDownloadStatus: string
{
    case Downloaded = 'downloaded';
    case NotModified = 'not_modified';
}
