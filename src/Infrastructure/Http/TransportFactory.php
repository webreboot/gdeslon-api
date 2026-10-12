<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Config;

final class TransportFactory
{
    /**
     * @param list<string>|null $rangeHosts
     */
    public static function fromConfig(#[\SensitiveParameter] Config $config, ?HttpTransport $inner = null, ?array $rangeHosts = null): HttpTransport
    {
        $transport = $inner ?? CurlTransport::fromConfig($config);
        $chunkSize = $config->rangeChunkSize();
        if ($chunkSize !== null) {
            $transport = new RangeTransport($transport, $chunkSize, hosts: $rangeHosts ?? RangeTransport::DEFAULT_HOSTS);
        }

        return $config->connectRetries() > 0 ? new RetryOnConnectTimeoutTransport($transport, $config->connectRetries()) : $transport;
    }
}
