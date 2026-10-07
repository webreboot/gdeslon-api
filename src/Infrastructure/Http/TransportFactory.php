<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Config;

/**
 * Встроенный транспорт по настройкам клиента: cURL, а для api.gdeslon.ru — с загрузкой частями.
 *
 * @internal
 */
final class TransportFactory
{
    /**
     * @param list<string>|null $rangeHosts хосты для загрузки частями (для тестов); null — по умолчанию RangeTransport
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
