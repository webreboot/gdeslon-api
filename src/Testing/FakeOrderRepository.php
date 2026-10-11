<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Testing;

use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Sales\OrderRepository;

final class FakeOrderRepository implements OrderRepository
{
    /** @var list<OrderCriteria> */
    private array $criteria = [];

    /**
     * @param list<Order>  $orders
     * @param list<string> $skipped
     */
    public function __construct(#[\SensitiveParameter] private readonly array $orders = [], private readonly array $skipped = [])
    {
    }

    public function find(#[\SensitiveParameter] OrderCriteria $criteria): OrderList
    {
        $this->criteria[] = $criteria;

        return new OrderList($criteria, $this->orders, $this->skipped);
    }

    /**
     * @return list<OrderCriteria>
     */
    public function criteria(): array
    {
        return $this->criteria;
    }
}
