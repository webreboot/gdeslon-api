<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

/** @internal */
final class ContentRange
{
    private function __construct(
        private readonly int $start,
        private readonly int $end,
        private readonly int $total,
    ) {
    }

    public static function parse(string $header): ?self
    {
        if (preg_match('~^bytes (\d{1,18})-(\d{1,18})/(\d{1,18})$~', trim($header), $match) !== 1) {
            return null;
        }

        [$start, $end, $total] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        if ($end < $start || $end >= $total) {
            return null;
        }

        return new self($start, $end, $total);
    }

    public function start(): int
    {
        return $this->start;
    }

    public function end(): int
    {
        return $this->end;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
