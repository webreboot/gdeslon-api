<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

use Webreboot\GdeSlon\Exception\TransportException;

/** @internal */
interface PartFetcher
{
    /**
     * @param list<array{int, int}>  $ranges
     * @param array<string, string> $headers
     *
     * @return list<PartResponse|TransportException>
     */
    public function fetch(#[\SensitiveParameter] FeedUrl $url, array $ranges, array $headers): array;
}
