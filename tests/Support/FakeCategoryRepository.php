<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Domain\Catalog\CategoryRepository;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;

final class FakeCategoryRepository implements CategoryRepository
{
    private int $calls = 0;

    public function __construct(private readonly CategoryTree $tree)
    {
    }

    public function all(): CategoryTree
    {
        $this->calls++;

        return $this->tree;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
