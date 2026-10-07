<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class SearchCriteria
{
    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 100;

    public const MAX_DEPTH = 10000;

    private readonly ?string $query;

    /** @var list<MerchantId> */
    private readonly array $merchants;

    /** @var list<MerchantId> */
    private readonly array $excludedMerchants;

    /** @var list<CategoryId> */
    private readonly array $categories;

    /** @var list<CategoryId> */
    private readonly array $excludedCategories;

    /** @var list<string> */
    private readonly array $articles;

    private readonly ?string $parkedDomain;

    /**
     * @param list<int|MerchantId> $merchants
     * @param list<int|MerchantId> $excludedMerchants
     * @param list<int|CategoryId> $categories
     * @param list<int|CategoryId> $excludedCategories
     * @param list<string>         $articles
     */
    public function __construct(
        ?string $query = null,
        array $merchants = [],
        array $excludedMerchants = [],
        array $categories = [],
        array $excludedCategories = [],
        array $articles = [],
        private readonly int $limit = self::DEFAULT_LIMIT,
        private readonly int $page = 1,
        private readonly ?OfferSort $sort = null,
        ?string $parkedDomain = null,
    ) {
        $query = $query === null ? '' : trim($query);
        $this->query = $query === '' ? null : $query;

        $this->merchants = self::merchantIds($merchants);
        $this->excludedMerchants = self::merchantIds($excludedMerchants);
        $this->categories = self::categoryIds($categories);
        $this->excludedCategories = self::categoryIds($excludedCategories);
        self::assertDisjoint('магазин', $this->merchants, $this->excludedMerchants);
        self::assertDisjoint('категория', $this->categories, $this->excludedCategories);

        $this->articles = self::normalizeArticles($articles);

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('limit должен быть от 1 до %d, получено %d', self::MAX_LIMIT, $limit));
        }
        if ($page < 1) {
            throw new InvalidArgumentException(sprintf('page должен быть не меньше 1, получено %d', $page));
        }
        if ($page * $limit > self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf(
                'Поиск отдаёт не глубже 10 000 офферов (page × limit), получено %d × %d',
                $page,
                $limit,
            ));
        }

        $this->parkedDomain = $parkedDomain === null ? null : self::normalizeParkedDomain($parkedDomain);
    }

    public function query(): ?string
    {
        return $this->query;
    }

    /**
     * @return list<MerchantId>
     */
    public function merchants(): array
    {
        return $this->merchants;
    }

    /**
     * @return list<MerchantId>
     */
    public function excludedMerchants(): array
    {
        return $this->excludedMerchants;
    }

    /**
     * @return list<CategoryId>
     */
    public function categories(): array
    {
        return $this->categories;
    }

    /**
     * @return list<CategoryId>
     */
    public function excludedCategories(): array
    {
        return $this->excludedCategories;
    }

    /**
     * @return list<string>
     */
    public function articles(): array
    {
        return $this->articles;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function sort(): ?OfferSort
    {
        return $this->sort;
    }

    public function parkedDomain(): ?string
    {
        return $this->parkedDomain;
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->query,
            $this->merchants,
            $this->excludedMerchants,
            $this->categories,
            $this->excludedCategories,
            $this->articles,
            $this->limit,
            $page,
            $this->sort,
            $this->parkedDomain,
        );
    }

    /**
     * @param list<int|MerchantId> $ids
     *
     * @return list<MerchantId>
     */
    private static function merchantIds(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $id = $id instanceof MerchantId ? $id : new MerchantId($id);
            $unique[$id->value()] ??= $id;
        }

        return array_values($unique);
    }

    /**
     * @param list<int|CategoryId> $ids
     *
     * @return list<CategoryId>
     */
    private static function categoryIds(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $id = $id instanceof CategoryId ? $id : new CategoryId($id);
            $unique[$id->value()] ??= $id;
        }

        return array_values($unique);
    }

    /**
     * @param list<MerchantId|CategoryId> $included
     * @param list<MerchantId|CategoryId> $excluded
     */
    private static function assertDisjoint(string $what, array $included, array $excluded): void
    {
        $excludedValues = [];
        foreach ($excluded as $id) {
            $excludedValues[] = $id->value();
        }
        $both = [];
        foreach ($included as $id) {
            if (in_array($id->value(), $excludedValues, true)) {
                $both[] = $id->value();
            }
        }
        if ($both !== []) {
            throw new InvalidArgumentException(sprintf('%s %s одновременно включён(а) и исключён(а)', ucfirst($what), implode(', ', $both)));
        }
    }

    /**
     * @param list<string> $articles
     *
     * @return list<string>
     */
    private static function normalizeArticles(array $articles): array
    {
        $unique = [];
        foreach ($articles as $article) {
            $article = trim($article);
            if ($article === '') {
                throw new InvalidArgumentException('Пустой артикул');
            }
            if (str_contains($article, ',')) {
                throw new InvalidArgumentException(sprintf('Артикул «%s» содержит запятую — API разделяет ими артикулы', $article));
            }
            $unique[$article] = $article;
        }

        return array_values($unique);
    }

    private static function normalizeParkedDomain(string $domain): string
    {
        $domain = rtrim(trim($domain), '/');
        if (preg_match('~^https?://[^/?#\s]+$~i', $domain) !== 1) {
            throw new InvalidArgumentException(sprintf('Припаркованный домен «%s»: ожидался адрес вида https://my.site.ru', $domain));
        }

        return $domain;
    }
}
