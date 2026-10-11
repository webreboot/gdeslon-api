<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Domain\Shared\DataOrigin;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * @implements \IteratorAggregate<int, Category>
 */
final class CategoryTree implements \IteratorAggregate, \Countable
{
    /** @var array<int, Category> */
    private array $byId = [];

    /** @var array<int, list<Category>> */
    private array $childrenByParent = [];

    /** @var array<int, list<Category>> */
    private array $descendantsByAncestor = [];

    private DataOrigin $origin = DataOrigin::Fetched;

    /**
     * @param iterable<Category> $categories
     */
    public function __construct(iterable $categories)
    {
        foreach ($categories as $category) {
            $id = $category->id()->value();
            if (isset($this->byId[$id])) {
                throw new InvalidArgumentException(sprintf('Категория %d встречается дважды', $id));
            }
            $this->byId[$id] = $category;

            $parentId = $category->parentId();
            if ($parentId !== null) {
                $this->childrenByParent[$parentId->value()][] = $category;
            }

            foreach (array_slice($category->path(), 0, -1) as $ancestor) {
                $this->descendantsByAncestor[$ancestor->value()][] = $category;
            }
        }
    }

    /**
     * @return list<Category>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function has(int|CategoryId $id): bool
    {
        return isset($this->byId[self::value($id)]);
    }

    public function find(int|CategoryId $id): ?Category
    {
        return $this->byId[self::value($id)] ?? null;
    }

    public function get(int|CategoryId $id): Category
    {
        return $this->find($id) ?? throw CategoryNotFoundException::forId($id);
    }

    /**
     * @return list<Category>
     */
    public function roots(): array
    {
        return array_values(array_filter($this->byId, static fn (Category $category): bool => $category->isRoot()));
    }

    /**
     * @return list<Category>
     */
    public function childrenOf(int|CategoryId $id): array
    {
        return $this->childrenByParent[self::value($id)] ?? [];
    }

    /**
     * @return list<Category>
     */
    public function descendantsOf(int|CategoryId $id): array
    {
        if (!$this->has($id)) {
            throw CategoryNotFoundException::forId($id);
        }

        return $this->descendantsByAncestor[self::value($id)] ?? [];
    }

    /**
     * @return list<Category>
     */
    public function breadcrumbs(int|CategoryId $id): array
    {
        $crumbs = [];
        foreach ($this->get($id)->path() as $step) {
            $category = $this->find($step);
            if ($category !== null) {
                $crumbs[] = $category;
            }
        }

        return $crumbs;
    }

    /**
     * @return list<Category>
     */
    public function orphans(): array
    {
        return array_values(array_filter(
            $this->byId,
            fn (Category $category): bool => $category->parentId() !== null && !$this->has($category->parentId()),
        ));
    }

    public function origin(): DataOrigin
    {
        return $this->origin;
    }

    public function withOrigin(DataOrigin $origin): self
    {
        $copy = clone $this;
        $copy->origin = $origin;

        return $copy;
    }

    public function count(): int
    {
        return count($this->byId);
    }

    /**
     * @return \ArrayIterator<int, Category>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    private static function value(int|CategoryId $id): int
    {
        return $id instanceof CategoryId ? $id->value() : $id;
    }
}
