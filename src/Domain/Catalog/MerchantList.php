<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Список магазинов: с токеном API — магазины вебмастера, без токена — публичный каталог «Где Слон?».
 *
 * Порядок — как в ответе API; он не стабилен между запросами, поэтому на него не стоит полагаться.
 *
 * @implements \IteratorAggregate<int, Merchant>
 */
final class MerchantList implements \IteratorAggregate, \Countable
{
    /** @var array<int, Merchant> */
    private array $byId = [];

    /**
     * @param iterable<Merchant> $merchants
     * @param list<string>       $skipped   почему записи ответа API не вошли в список (битые данные отдельных магазинов)
     */
    public function __construct(iterable $merchants, private readonly array $skipped = [])
    {
        foreach ($merchants as $merchant) {
            $id = $merchant->id()->value();
            if (isset($this->byId[$id])) {
                throw new InvalidArgumentException(sprintf('Магазин %d встречается дважды', $id));
            }
            $this->byId[$id] = $merchant;
        }
    }

    /**
     * @return list<Merchant>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function has(int|MerchantId $id): bool
    {
        return isset($this->byId[self::value($id)]);
    }

    public function find(int|MerchantId $id): ?Merchant
    {
        return $this->byId[self::value($id)] ?? null;
    }

    /**
     * @throws MerchantNotFoundException
     */
    public function get(int|MerchantId $id): Merchant
    {
        return $this->find($id) ?? throw MerchantNotFoundException::forId($id);
    }

    /**
     * Магазины с этим сайтом: хост или URL, без учёта регистра и «www.» («https://www.komus.ru/x» → komus.ru).
     *
     * @return list<Merchant>
     */
    public function findByDomain(string $hostOrUrl): array
    {
        $host = Merchant::hostOf($hostOrUrl);
        if ($host === '') {
            throw new InvalidArgumentException(sprintf('Не удалось выделить домен из «%s»', $hostOrUrl));
        }

        return $this->filter(static fn (Merchant $merchant): bool => $merchant->domain() === $host);
    }

    /**
     * Магазины, в названии или домене которых есть подстрока (без учёта регистра, буквально).
     *
     * @return list<Merchant>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException('Пустой поисковый запрос');
        }
        $pattern = '/' . preg_quote($query, '/') . '/iu';

        return $this->filter(
            static fn (Merchant $merchant): bool => preg_match($pattern, $merchant->name()) === 1
                || preg_match($pattern, $merchant->domain()) === 1,
        );
    }

    /**
     * @return list<Merchant> магазины категории магазинов (MerchantCategory)
     */
    public function inCategory(int $categoryId): array
    {
        return $this->filter(static function (Merchant $merchant) use ($categoryId): bool {
            foreach ($merchant->categories() as $category) {
                if ($category->id() === $categoryId) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @return list<MerchantCategory> категории магазинов списка, без повторов, по возрастанию ID
     */
    public function categories(): array
    {
        $categories = [];
        foreach ($this->byId as $merchant) {
            foreach ($merchant->categories() as $category) {
                $categories[$category->id()] ??= $category;
            }
        }
        ksort($categories);

        return array_values($categories);
    }

    /**
     * @param callable(Merchant): bool $predicate
     *
     * @return list<Merchant>
     */
    public function filter(callable $predicate): array
    {
        return array_values(array_filter($this->byId, $predicate));
    }

    /**
     * Причины, по которым отдельные магазины из ответа API пропущены (битые данные). Пусто — пропусков нет.
     *
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
     * @return \ArrayIterator<int, Merchant>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    private static function value(int|MerchantId $id): int
    {
        return $id instanceof MerchantId ? $id->value() : $id;
    }
}
