<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * @implements \IteratorAggregate<int, Coupon>
 */
final class CouponList implements \IteratorAggregate, \Countable
{
    /** @var array<int, Coupon> */
    private array $byId = [];

    /**
     * @param iterable<Coupon> $coupons
     * @param list<CouponKind> $kinds
     * @param list<string>     $skipped
     */
    public function __construct(
        private readonly CouponCriteria $criteria,
        iterable $coupons,
        private readonly array $kinds = [],
        private readonly array $skipped = [],
    ) {
        foreach ($coupons as $coupon) {
            $id = $coupon->id()->value();
            if (isset($this->byId[$id])) {
                throw new InvalidArgumentException(sprintf('Купон %d встречается дважды', $id));
            }
            $this->byId[$id] = $coupon;
        }
    }

    public function criteria(): CouponCriteria
    {
        return $this->criteria;
    }

    /**
     * @return list<Coupon>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(int|CouponId $id): ?Coupon
    {
        return $this->byId[$id instanceof CouponId ? $id->value() : $id] ?? null;
    }

    /**
     * @return list<Coupon>
     */
    public function activeAt(\DateTimeInterface $moment): array
    {
        return $this->filter(static fn (Coupon $coupon): bool => $coupon->isActiveAt($moment));
    }

    /**
     * @param callable(Coupon): bool $predicate
     *
     * @return list<Coupon>
     */
    public function filter(callable $predicate): array
    {
        return array_values(array_filter($this->byId, $predicate));
    }

    /**
     * @return list<CouponKind>
     */
    public function kinds(): array
    {
        return $this->kinds;
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
     * @return \ArrayIterator<int, Coupon>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }
}
