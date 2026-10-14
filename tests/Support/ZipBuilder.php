<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

final class ZipBuilder
{
    public const STORED = 0;

    public const DEFLATED = 8;

    private const DATA_DESCRIPTOR_FLAG = 8;

    /** @var list<array{name: string, data: string, method: int, flags: int, crc: ?int, usize: ?int, csize: ?int, payload: ?string, localExtra: string, centralExtra: string, dataDescriptor: bool}> */
    private array $entries = [];

    private bool $zip64 = false;

    public function add(
        string $name,
        string $data,
        int $method = self::DEFLATED,
        int $flags = 0,
        ?int $crc = null,
        ?int $usize = null,
        ?int $csize = null,
        ?string $payload = null,
        string $localExtra = '',
        string $centralExtra = '',
        bool $dataDescriptor = false,
    ): self {
        $this->entries[] = [
            'name' => $name,
            'data' => $data,
            'method' => $method,
            'flags' => $flags | ($dataDescriptor ? self::DATA_DESCRIPTOR_FLAG : 0),
            'crc' => $crc,
            'usize' => $usize,
            'csize' => $csize,
            'payload' => $payload,
            'localExtra' => $localExtra,
            'centralExtra' => $centralExtra,
            'dataDescriptor' => $dataDescriptor,
        ];

        return $this;
    }

    public function zip64(): self
    {
        $this->zip64 = true;

        return $this;
    }

    public function build(): string
    {
        $local = '';
        $central = '';
        foreach ($this->entries as $entry) {
            $payload = $entry['payload'] ?? match ($entry['method']) {
                self::STORED => $entry['data'],
                self::DEFLATED => (string) gzdeflate($entry['data'], 6),
                default => $entry['data'],
            };
            $crc = $entry['crc'] ?? crc32($entry['data']);
            $usize = $entry['usize'] ?? strlen($entry['data']);
            $csize = $entry['csize'] ?? strlen($payload);
            $offset = strlen($local);
            [$localCrc, $localCsize, $localUsize] = $entry['dataDescriptor'] ? [0, 0, 0] : [$crc, $csize, $usize];
            $local .= pack('VvvvvvVVVvv', 0x04034B50, 20, $entry['flags'], $entry['method'], 0, 0x5D4D, $localCrc, $localCsize, $localUsize, strlen($entry['name']), strlen($entry['localExtra']))
                . $entry['name'] . $entry['localExtra'] . $payload
                . ($entry['dataDescriptor'] ? pack('VVVV', 0x08074B50, $crc, $csize, $usize) : '');
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $entry['flags'], $entry['method'], 0, 0x5D4D, $crc, $csize, $usize, strlen($entry['name']), strlen($entry['centralExtra']), 0, 0, 0, 0, $offset)
                . $entry['name'] . $entry['centralExtra'];
        }
        $count = $this->zip64 ? 0xFFFF : count($this->entries);
        $centralOffset = $this->zip64 ? 0xFFFFFFFF : strlen($local);

        return $local . $central . pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($central), $centralOffset, 0);
    }

    public function writeTo(string $path): string
    {
        file_put_contents($path, $this->build());

        return $path;
    }
}
