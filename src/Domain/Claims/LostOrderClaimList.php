<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Заявки по критериям. Порядок — как в ответе API.
 *
 * @implements \IteratorAggregate<int, LostOrderClaim>
 */
final class LostOrderClaimList implements \IteratorAggregate, \Countable
{
    /** @var array<int, LostOrderClaim> */
    private array $byId = [];

    /**
     * @param iterable<LostOrderClaim> $claims
     * @param list<string>             $skipped почему записи ответа API не вошли в список (битые данные)
     */
    public function __construct(private readonly LostOrderCriteria $criteria, iterable $claims, private readonly array $skipped = [])
    {
        foreach ($claims as $claim) {
            $id = $claim->id()->value();
            if (isset($this->byId[$id])) {
                throw new InvalidArgumentException(sprintf('Заявка %d встречается дважды', $id));
            }
            $this->byId[$id] = $claim;
        }
    }

    public function criteria(): LostOrderCriteria
    {
        return $this->criteria;
    }

    /**
     * @return list<LostOrderClaim>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(int|LostOrderClaimId $id): ?LostOrderClaim
    {
        return $this->byId[$id instanceof LostOrderClaimId ? $id->value() : $id] ?? null;
    }

    /**
     * Заявка на тот же заказ этого магазина: номер без пробелов по краям и без учёта регистра латиницы.
     */
    public function findByOrderNumber(MerchantId $merchant, string $orderNumber): ?LostOrderClaim
    {
        $orderNumber = strtolower(trim($orderNumber));
        foreach ($this->byId as $claim) {
            if ($claim->merchantId()->equals($merchant) && strtolower($claim->orderNumber()) === $orderNumber) {
                return $claim;
            }
        }

        return null;
    }

    /**
     * @param callable(LostOrderClaim): bool $predicate
     *
     * @return list<LostOrderClaim>
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
     * @return \ArrayIterator<int, LostOrderClaim>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }
}
