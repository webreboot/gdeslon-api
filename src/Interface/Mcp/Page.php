<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

/**
 * @internal
 */
final class Page
{
    private const MAX_OFFSET = 1000000;

    private function __construct(private readonly int $limit, private readonly int $offset)
    {
    }

    public static function of(Arguments $arguments, int $defaultLimit, int $maxLimit): self
    {
        return new self($arguments->int('limit', 1, $maxLimit) ?? $defaultLimit, $arguments->int('offset', 0, self::MAX_OFFSET) ?? 0);
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public function slice(array $items): array
    {
        return array_slice($items, $this->offset, $this->limit);
    }

    /**
     * @return array{total: int, limit: int, offset: int, next_offset: int|null}
     */
    public function meta(int $total): array
    {
        $next = $this->offset + $this->limit;

        return ['total' => $total, 'limit' => $this->limit, 'offset' => $this->offset, 'next_offset' => $next < $total ? $next : null];
    }

    /**
     * @return array<string, \stdClass>
     */
    public static function inputSchema(int $defaultLimit, int $maxLimit): array
    {
        return [
            'limit' => Json::object(['type' => 'integer', 'minimum' => 1, 'maximum' => $maxLimit, 'default' => $defaultLimit, 'description' => 'записей на странице']),
            'offset' => Json::object(['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'сколько записей пропустить (next_offset из прошлого ответа)']),
        ];
    }
}
