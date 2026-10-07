<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Sales;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * @implements \IteratorAggregate<int, Order>
 */
final class OrderList implements \IteratorAggregate, \Countable
{
    /** @var array<string, Order> */
    private array $byId = [];

    /**
     * @param iterable<Order> $orders
     * @param list<string>    $skipped
     */
    public function __construct(private readonly OrderCriteria $criteria, iterable $orders, private readonly array $skipped = [])
    {
        foreach ($orders as $order) {
            $id = $order->id()->value();
            if (isset($this->byId[$id])) {
                throw new InvalidArgumentException(sprintf('Заказ %s встречается дважды', $id));
            }
            $this->byId[$id] = $order;
        }
    }

    public function criteria(): OrderCriteria
    {
        return $this->criteria;
    }

    /**
     * @return list<Order>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(OrderId|string $id): ?Order
    {
        return $this->byId[$id instanceof OrderId ? $id->value() : trim($id)] ?? null;
    }

    /**
     * @param callable(Order): bool $predicate
     *
     * @return list<Order>
     */
    public function filter(callable $predicate): array
    {
        return array_values(array_filter($this->byId, $predicate));
    }

    public function isEmpty(): bool
    {
        return $this->byId === [];
    }

    /**
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function count(): int
    {
        return count($this->byId);
    }

    /**
     * @return \ArrayIterator<int, Order>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }
}
