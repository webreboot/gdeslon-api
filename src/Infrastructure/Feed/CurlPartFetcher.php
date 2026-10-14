<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\SecretMasker;

/** @internal */
final class CurlPartFetcher implements PartFetcher
{
    private const OPERATION_TIMEDOUT = 28;

    private const WRITE_ERROR = 23;

    private const SELECT_TIMEOUT = 1.0;

    private readonly string $userAgent;

    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        ?string $userAgent = null,
    ) {
        $this->userAgent = $userAgent ?? CurlTransport::defaultUserAgent();
    }

    public static function fromConfig(#[\SensitiveParameter] Config $config): self
    {
        return new self($config->timeout(), $config->connectTimeout(), $config->userAgent());
    }

    public function fetch(#[\SensitiveParameter] FeedUrl $url, array $ranges, array $headers): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $states = [];
        foreach ($ranges as $index => [$start, $end]) {
            $state = new PartState($end - $start + 1);
            $handle = curl_init();
            curl_setopt_array($handle, $this->options($url, $start, $end, $headers, $state));
            curl_multi_add_handle($multi, $handle);
            $handles[$index] = $handle;
            $states[$index] = $state;
        }

        try {
            $codes = self::run($multi);
            $results = [];
            foreach ($handles as $index => $handle) {
                $results[] = $this->result($url, $handle, $states[$index], $codes[spl_object_id($handle)] ?? CURLE_OK);
            }

            return $results;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<int, mixed>
     */
    private function options(#[\SensitiveParameter] FeedUrl $url, int $start, int $end, array $headers, #[\SensitiveParameter] PartState $state): array
    {
        $lines = ['Connection: close'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return [
            CURLOPT_URL => $url->value(),
            CURLOPT_HTTPGET => true,
            CURLOPT_RANGE => $start . '-' . $end,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT_MS => (int) round($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // хост обрывает соединение после ~16 КБ: каждая часть — в новом соединении
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use ($state): int {
                $state->header($line);

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static fn (\CurlHandle $handle, string $data): int => $state->write($data),
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function run(\CurlMultiHandle $multi): array
    {
        $codes = [];
        do {
            $status = curl_multi_exec($multi, $running);
            self::collect($multi, $codes);
            if ($running > 0 && curl_multi_select($multi, self::SELECT_TIMEOUT) === -1) {
                usleep(1000);
            }
        } while ($running > 0 && $status === CURLM_OK);
        self::collect($multi, $codes);

        return $codes;
    }

    /**
     * @param array<int, int> $codes
     */
    private static function collect(\CurlMultiHandle $multi, array &$codes): void
    {
        while (is_array($info = curl_multi_info_read($multi))) {
            $handle = $info['handle'] ?? null;
            $result = $info['result'] ?? null;
            if ($handle instanceof \CurlHandle && is_int($result)) {
                $codes[spl_object_id($handle)] = $result;
            }
        }
    }

    private function result(#[\SensitiveParameter] FeedUrl $url, \CurlHandle $handle, #[\SensitiveParameter] PartState $state, int $code): PartResponse|TransportException
    {
        if ($code === CURLE_OK || ($code === self::WRITE_ERROR && $state->truncated)) {
            return new PartResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $state->headers, $state->body, $state->truncated);
        }

        $connecting = curl_getinfo($handle, CURLINFO_REQUEST_SIZE) === 0 && (float) curl_getinfo($handle, CURLINFO_SIZE_DOWNLOAD) === 0.0;
        $reason = match (true) {
            $code !== self::OPERATION_TIMEDOUT => 'ошибка соединения',
            $connecting => 'превышено время установки соединения',
            default => 'превышено время ожидания',
        };
        $message = sprintf('GET %s: %s (cURL %d: %s)', $url->masked(), $reason, $code, SecretMasker::maskValues((string) curl_strerror($code), $url->secrets()));

        return match (true) {
            $code !== self::OPERATION_TIMEDOUT => new TransportException($message, 'GET', $url->masked(), $code),
            $connecting => new ConnectTimeoutException($message, 'GET', $url->masked(), $code),
            default => new TimeoutException($message, 'GET', $url->masked(), $code),
        };
    }
}
