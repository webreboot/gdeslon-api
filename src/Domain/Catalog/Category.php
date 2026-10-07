<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Категория товарного классификатора «Где Слон?».
 *
 * Путь (path) — ID от корня до самой категории включительно, как его отдаёт API. В нём могут быть ID категорий,
 * которых нет в выгрузке («сироты» — реальное состояние данных, а не ошибка).
 */
final class Category
{
    private readonly string $name;

    /**
     * @param list<CategoryId> $path       ID от корня до этой категории включительно
     * @param int|null         $offerCount число офферов; null — неизвестно (API отдаёт его не для всех категорий)
     */
    public function __construct(
        private readonly CategoryId $id,
        private readonly ?CategoryId $parentId,
        string $name,
        private readonly bool $archived,
        private readonly array $path,
        private readonly ?int $offerCount,
    ) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException(sprintf('Пустое название категории %s', $id));
        }
        $this->name = $name;

        self::assertPathIsConsistent($id, $parentId, $path);

        if ($offerCount !== null && $offerCount < 0) {
            throw new InvalidArgumentException(sprintf('Отрицательное число офферов %d у категории %s', $offerCount, $id));
        }
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function parentId(): ?CategoryId
    {
        return $this->parentId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    /**
     * @return list<CategoryId>
     */
    public function path(): array
    {
        return $this->path;
    }

    /**
     * Уровень вложенности: 1 — корневая категория.
     */
    public function depth(): int
    {
        return count($this->path);
    }

    /**
     * Число офферов в категории; null — API его не сообщает (это не ноль).
     */
    public function offerCount(): ?int
    {
        return $this->offerCount;
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    /**
     * @param list<CategoryId> $path
     */
    private static function assertPathIsConsistent(CategoryId $id, ?CategoryId $parentId, array $path): void
    {
        $count = count($path);
        if ($count === 0) {
            throw new InvalidArgumentException(sprintf('Пустой путь у категории %s', $id));
        }
        if (!$path[$count - 1]->equals($id)) {
            throw new InvalidArgumentException(sprintf('Путь категории %s должен заканчиваться ею самой', $id));
        }

        $values = array_map(static fn (CategoryId $item): int => $item->value(), $path);
        if (count(array_unique($values)) !== $count) {
            throw new InvalidArgumentException(sprintf('Повтор в пути категории %s: %s', $id, implode(',', $values)));
        }

        if ($parentId === null) {
            if ($count !== 1) {
                throw new InvalidArgumentException(sprintf('У корневой категории %s путь должен состоять из неё самой', $id));
            }

            return;
        }

        if ($count < 2 || !$path[$count - 2]->equals($parentId)) {
            throw new InvalidArgumentException(sprintf(
                'Путь категории %s (%s) не проходит через родителя %s',
                $id,
                implode(',', $values),
                $parentId,
            ));
        }
    }
}
