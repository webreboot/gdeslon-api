<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Domain\Catalog\CategoryRepository;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;

final class FakeCategoryRepository implements CategoryRepository
{
    private int $calls = 0;

    private ?bool $lastRefresh = null;

    public function __construct(private readonly CategoryTree $tree)
    {
    }

    public function all(bool $refresh = false): CategoryTree
    {
        $this->calls++;
        $this->lastRefresh = $refresh;

        return $this->tree;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function lastRefresh(): ?bool
    {
        return $this->lastRefresh;
    }
}
