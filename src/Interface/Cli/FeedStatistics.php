<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Domain\Catalog\ProductFeed;

/**
 * @internal
 */
final class FeedStatistics
{
    private const TOP_MERCHANTS = 10;

    /**
     * @param array<int, int>        $merchants
     * @param array<int|string, int> $params
     * @param array{color: int, size: int, gender: int, erid: int} $coverage
     */
    private function __construct(
        public readonly ?\DateTimeImmutable $generatedAt,
        public readonly int $offers,
        public readonly int $skipped,
        public readonly int $categories,
        public readonly array $merchants,
        public readonly array $params,
        public readonly array $coverage,
    ) {
    }

    public static function of(ProductFeed $feed): self
    {
        $offers = 0;
        $merchants = [];
        $params = [];
        $coverage = ['color' => 0, 'size' => 0, 'gender' => 0, 'erid' => 0];
        foreach ($feed->offers() as $offer) {
            $offers++;
            $merchant = $offer->merchantId()->value();
            $merchants[$merchant] = ($merchants[$merchant] ?? 0) + 1;
            $names = [];
            $hasSize = false;
            foreach ($offer->params() as $param) {
                $names[$param->name()] = true;
                $hasSize = $hasSize || preg_match('/^размер/iu', $param->name()) === 1;
            }
            foreach (array_keys($names) as $name) {
                $params[(string) $name] = ($params[(string) $name] ?? 0) + 1;
            }
            $coverage['color'] += $offer->param('Цвет') === null ? 0 : 1;
            $coverage['size'] += $hasSize ? 1 : 0;
            $coverage['gender'] += $offer->param('Пол') === null ? 0 : 1;
            $coverage['erid'] += $offer->erid() === null ? 0 : 1;
        }

        uksort($merchants, static fn (int $a, int $b): int => [$merchants[$b], $a] <=> [$merchants[$a], $b]);
        uksort($params, static fn (int|string $a, int|string $b): int => [$params[$b], (string) $a] <=> [$params[$a], (string) $b]);

        return new self($feed->generatedAt(), $offers, $feed->skippedCount(), count($feed->categories()), $merchants, $params, $coverage);
    }

    /**
     * @return array<int, int>
     */
    public function topMerchants(): array
    {
        return array_slice($this->merchants, 0, self::TOP_MERCHANTS, true);
    }

    public function share(int $count): float
    {
        return $this->offers === 0 ? 0.0 : round($count / $this->offers, 2);
    }

    public function percent(int $count): string
    {
        return sprintf('%d%%', $this->offers === 0 ? 0 : (int) round($count * 100 / $this->offers));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt?->format(DATE_ATOM),
            'offers' => $this->offers,
            'skipped' => $this->skipped,
            'categories' => $this->categories,
            'merchants' => [
                'count' => count($this->merchants),
                'top' => array_map(
                    static fn (int $id, int $count): array => ['merchant_id' => $id, 'offers' => $count],
                    array_keys($this->topMerchants()),
                    array_values($this->topMerchants()),
                ),
            ],
            'params' => array_map(
                fn (string $name, int $count): array => ['name' => $name, 'offers' => $count, 'share' => $this->share($count)],
                array_map('strval', array_keys($this->params)),
                array_values($this->params),
            ),
            'coverage' => array_map(
                fn (int $count): array => ['offers' => $count, 'share' => $this->share($count)],
                $this->coverage,
            ),
        ];
    }
}
