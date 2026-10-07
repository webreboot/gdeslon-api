<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Магазин (рекламодатель) — партнёрская программа «Где Слон?».
 *
 * Тексты (описание, условия) — markdown как в API, переводы строк приведены к `\n`. Сводка вознаграждения и
 * маркировка рекламы — свободный текст для показа. Новые параметры конструктора добавляются только в конец.
 */
final class Merchant
{
    private readonly string $name;

    private readonly string $description;

    private readonly string $conditions;

    private readonly ?string $commissionSummary;

    private readonly ?string $adMarking;

    /**
     * @param string                 $url               сайт магазина
     * @param string|null            $affiliateLink     готовая партнёрская ссылка вебмастера (только с токеном API)
     * @param string|null            $commissionSummary сводка вознаграждения для показа («5% - 16,49%»)
     * @param list<MerchantCategory> $categories
     * @param list<TrafficType>      $trafficTypes      типы трафика в порядке API
     * @param list<Tariff>           $tariffs
     * @param list<CategoryTariff>   $categoryTariffs   ставки по категориям товаров магазина
     * @param string|null            $adMarking         маркировка рекламы («Реклама. Рекламодатель … erid …»)
     */
    public function __construct(
        private readonly MerchantId $id,
        string $name,
        private readonly string $url,
        private readonly string $shortDescription = '',
        string $description = '',
        string $conditions = '',
        private readonly ?string $logoUrl = null,
        private readonly ?string $country = null,
        private readonly ?string $kind = null,
        private readonly bool $green = false,
        ?string $commissionSummary = null,
        private readonly array $categories = [],
        private readonly ?string $affiliateLink = null,
        private readonly array $trafficTypes = [],
        private readonly array $tariffs = [],
        private readonly array $categoryTariffs = [],
        ?string $adMarking = null,
    ) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException(sprintf('Пустое название магазина %s', $id));
        }
        self::assertHttpUrl($url, sprintf('Сайт магазина %s', $id));
        if ($affiliateLink !== null) {
            self::assertHttpUrl($affiliateLink, sprintf('Партнёрская ссылка магазина %s', $id));
        }

        $names = array_map(static fn (TrafficType $type): string => $type->name(), $trafficTypes);
        if (count(array_unique($names)) !== count($names)) {
            throw new InvalidArgumentException(sprintf('Повтор типа трафика у магазина %s', $id));
        }

        $this->name = $name;
        $this->description = self::normalizeLineEndings($description);
        $this->conditions = self::normalizeLineEndings($conditions);
        $this->commissionSummary = self::nullIfBlank($commissionSummary);
        $this->adMarking = self::nullIfBlank($adMarking);
    }

    public function id(): MerchantId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * Хост сайта без «www.» в нижнем регистре: «https://www.komus.ru/» → «komus.ru».
     */
    public function domain(): string
    {
        return self::hostOf($this->url);
    }

    public function shortDescription(): string
    {
        return $this->shortDescription;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function conditions(): string
    {
        return $this->conditions;
    }

    public function logoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function country(): ?string
    {
        return $this->country;
    }

    /**
     * Форма рекламодателя как в API: «Юридическое лицо», «Физическое лицо», «Индивидуальный предприниматель».
     */
    public function kind(): ?string
    {
        return $this->kind;
    }

    public function isGreen(): bool
    {
        return $this->green;
    }

    public function commissionSummary(): ?string
    {
        return $this->commissionSummary;
    }

    /**
     * @return list<MerchantCategory>
     */
    public function categories(): array
    {
        return $this->categories;
    }

    public function affiliateLink(): ?string
    {
        return $this->affiliateLink;
    }

    /**
     * @return list<TrafficType>
     */
    public function trafficTypes(): array
    {
        return $this->trafficTypes;
    }

    /**
     * @return list<string> названия разрешённых типов трафика
     */
    public function allowedTrafficTypes(): array
    {
        return $this->trafficTypeNames(true);
    }

    /**
     * @return list<string> названия запрещённых типов трафика
     */
    public function forbiddenTrafficTypes(): array
    {
        return $this->trafficTypeNames(false);
    }

    /**
     * true/false — разрешён ли тип трафика; null — магазин такой тип не указывает.
     */
    public function isTrafficTypeAllowed(string $name): ?bool
    {
        foreach ($this->trafficTypes as $type) {
            if ($type->name() === $name) {
                return $type->isAllowed();
            }
        }

        return null;
    }

    /**
     * @return list<Tariff>
     */
    public function tariffs(): array
    {
        return $this->tariffs;
    }

    /**
     * @return list<CategoryTariff>
     */
    public function categoryTariffs(): array
    {
        return $this->categoryTariffs;
    }

    public function adMarking(): ?string
    {
        return $this->adMarking;
    }

    /**
     * @internal хост в форме Merchant::domain(); пустая строка — не URL с хостом
     */
    public static function hostOf(string $hostOrUrl): string
    {
        $value = strtolower(trim($hostOrUrl));
        $host = str_contains($value, '://') ? parse_url($value, PHP_URL_HOST) : explode('/', $value, 2)[0];
        $host = is_string($host) ? $host : '';

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @return list<string>
     */
    private function trafficTypeNames(bool $allowed): array
    {
        $names = [];
        foreach ($this->trafficTypes as $type) {
            if ($type->isAllowed() === $allowed) {
                $names[] = $type->name();
            }
        }

        return $names;
    }

    private static function assertHttpUrl(string $url, string $what): void
    {
        if (preg_match('~^https?://[^\s/?#]+~i', $url) !== 1) {
            throw new InvalidArgumentException(sprintf('%s: ожидался адрес http(s)://, получено «%s»', $what, $url));
        }
    }

    private static function normalizeLineEndings(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }

    private static function nullIfBlank(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : $text;
    }
}
