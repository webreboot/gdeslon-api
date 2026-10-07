<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Xml;

/**
 * @template T
 *
 * @internal
 */
final class XmlRecords
{
    /**
     * @param list<T>               $records
     * @param list<string>          $skipped
     * @param array<string, string> $captured
     */
    public function __construct(
        private readonly array $records,
        private readonly array $skipped,
        private readonly array $captured,
    ) {
    }

    /**
     * @return list<T>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function captured(string $path): ?string
    {
        return $this->captured[$path] ?? null;
    }
}
