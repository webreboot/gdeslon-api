<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Config;

/** @internal */
final class TransportFactory
{
    /**
     * @param list<string>|null $rangeHosts
     */
    public static function fromConfig(Config $config, ?array $rangeHosts = null): HttpTransport
    {
        $curl = CurlTransport::fromConfig($config);
        $chunkSize = $config->rangeChunkSize();

        if ($chunkSize === null) {
            return $curl;
        }

        return new RangeTransport($curl, $chunkSize, hosts: $rangeHosts ?? RangeTransport::DEFAULT_HOSTS);
    }
}
