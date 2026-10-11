<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Subcategories
{
    private function __construct()
    {
    }

    /**
     * @param list<int> $included
     * @param list<int> $excluded
     */
    public static function expand(CategoryTree $tree, SearchCriteria $criteria, array $included, array $excluded): SearchCriteria
    {
        $includedSubtrees = self::subtrees($tree, $included);
        $excludedSubtrees = self::subtrees($tree, $excluded);
        $remaining = array_values(array_diff($includedSubtrees, $excludedSubtrees));
        if ($included !== [] && $remaining === []) {
            throw new InvalidArgumentException(sprintf(
                'Все категории поиска (%s) исключены вместе с подкатегориями — искать не в чем',
                implode(',', $included),
            ));
        }

        return new SearchCriteria(
            $criteria->query(),
            $criteria->merchants(),
            $criteria->excludedMerchants(),
            $remaining,
            $included === [] ? $excludedSubtrees : [],
            $criteria->articles(),
            $criteria->limit(),
            $criteria->page(),
            $criteria->sort(),
            $criteria->parkedDomain(),
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function subtrees(CategoryTree $tree, array $ids): array
    {
        $subtrees = [];
        foreach ($ids as $id) {
            $subtrees[$id] = $id;
            foreach ($tree->descendantsOf($id) as $descendant) {
                $subtrees[$descendant->id()->value()] = $descendant->id()->value();
            }
        }

        return array_values($subtrees);
    }
}
