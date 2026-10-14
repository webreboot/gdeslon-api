<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\GdeSlonException;

interface ProductFeed
{
    public function generatedAt(): ?\DateTimeImmutable;

    public function categories(): CategoryTree;

    /**
     * @return \Traversable<int, Offer>
     *
     * @throws GdeSlonException
     */
    public function offers(): \Traversable;

    /**
     * @return list<string>
     */
    public function skipped(): array;

    public function skippedCount(): int;
}
