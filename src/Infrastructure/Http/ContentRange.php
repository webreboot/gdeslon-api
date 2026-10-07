<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

/**
 * Заголовок Content-Range одного диапазона байт: `bytes S-E/T` (RFC 9110, 14.4).
 *
 * @internal
 */
final class ContentRange
{
    private function __construct(
        private readonly int $start,
        private readonly int $end,
        private readonly int $total,
    ) {
    }

    /**
     * null — если это не один диапазон с известным размером: ответ на 416 (звёздочка вместо диапазона),
     * неизвестный размер (звёздочка вместо T), multipart, мусор.
     */
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
