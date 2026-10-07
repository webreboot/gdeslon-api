<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Sales\OrderRepository;

final class FakeOrderRepository implements OrderRepository
{
    /** @var list<OrderCriteria> */
    private array $criteria = [];

    public function find(OrderCriteria $criteria): OrderList
    {
        $this->criteria[] = $criteria;

        return new OrderList($criteria, []);
    }

    /**
     * @return list<OrderCriteria>
     */
    public function criteria(): array
    {
        return $this->criteria;
    }
}
