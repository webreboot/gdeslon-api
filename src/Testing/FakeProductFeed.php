<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Testing;

use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\ProductFeed;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Infrastructure\Yml\YmlProductFeed;

final class FakeProductFeed implements ProductFeed
{
    private int $traversals = 0;

    private readonly int $skippedCount;

    /**
     * @param list<Offer>  $offers
     * @param list<string> $skipped
     */
    public function __construct(
        private readonly array $offers = [],
        private readonly CategoryTree $categories = new CategoryTree([]),
        private readonly ?\DateTimeImmutable $generatedAt = null,
        private readonly array $skipped = [],
        ?int $skippedCount = null,
    ) {
        $this->skippedCount = max($skippedCount ?? 0, count($skipped));
    }

    /**
     * @throws GdeSlonException
     */
    public static function fromYmlFile(string $path): self
    {
        $feed = YmlProductFeed::open($path);
        $offers = iterator_to_array($feed->offers(), false);

        return new self($offers, $feed->categories(), $feed->generatedAt(), $feed->skipped(), $feed->skippedCount());
    }

    /**
     * @throws GdeSlonException
     */
    public static function fromYml(#[\SensitiveParameter] string $xml): self
    {
        $path = tempnam(sys_get_temp_dir(), 'gdeslon-feed');
        if ($path === false) {
            throw new \RuntimeException('FakeProductFeed: не удалось создать временный файл');
        }

        try {
            file_put_contents($path, $xml);

            return self::fromYmlFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function generatedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function categories(): CategoryTree
    {
        return $this->categories;
    }

    /**
     * @return \Generator<int, Offer>
     */
    public function offers(): \Generator
    {
        $this->traversals++;

        yield from $this->offers;
    }

    public function skipped(): array
    {
        return $this->skipped;
    }

    public function skippedCount(): int
    {
        return $this->skippedCount;
    }

    public function traversals(): int
    {
        return $this->traversals;
    }
}
