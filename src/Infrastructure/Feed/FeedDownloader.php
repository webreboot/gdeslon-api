<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

use Webreboot\GdeSlon\Exception\GdeSlonException;

interface FeedDownloader
{
    /**
     * @throws GdeSlonException
     */
    public function download(#[\SensitiveParameter] FeedUrl $url, string $target, ?FeedValidators $previous = null): FeedDownload;
}
