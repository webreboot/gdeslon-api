<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Купон или промокод магазина. Неизменяемый.
 *
 * Купон без промокода — акция (code() === null, ссылки с кодом нет). ⚠️ Партнёрские ссылки купонов API строит с
 * токеном XML API в пути (`/ck/<токен>/<id>`): публикация ссылки раскрывает токен. В var_dump/print_r токен в ссылках
 * заменён звёздочками, но serialize(), var_export() и JSON со ссылками хранят его открытым текстом — кэшируйте купоны
 * только там, где допустимо хранить токен. Публикуя купон как рекламу, показывайте рядом маркировку adMarking(). Новые параметры конструктора
 * добавляются только в конец (вызывайте с именами).
 */
final class Coupon
{
    private readonly CouponId $id;

    private readonly MerchantId $merchantId;

    private readonly string $name;

    private readonly string $description;

    private readonly ?string $instruction;

    private readonly ?string $code;

    /** @var list<CouponCategory> */
    private readonly array $categories;

    private readonly ?string $affiliateLinkWithCode;

    private readonly ?string $merchantName;

    private readonly ?string $adMarking;

    /**
     * @param list<CouponCategory> $categories
     */
    public function __construct(
        int|CouponId $id,
        int|MerchantId $merchantId,
        string $name,
        private readonly CouponKind $kind,
        private readonly \DateTimeImmutable $startsAt,
        private readonly \DateTimeImmutable $endsAt,
        private readonly string $affiliateLink,
        ?string $merchantName = null,
        string $description = '',
        ?string $instruction = null,
        ?string $code = null,
        array $categories = [],
        ?string $affiliateLinkWithCode = null,
        ?string $adMarking = null,
    ) {
        $this->id = is_int($id) ? new CouponId($id) : $id;
        $this->merchantId = is_int($merchantId) ? new MerchantId($merchantId) : $merchantId;

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException(sprintf('Купон %s: пустое название', $this->id));
        }
        if ($startsAt > $endsAt) {
            throw new InvalidArgumentException(sprintf('Купон %s: начало действия позже окончания', $this->id));
        }
        self::assertLink($affiliateLink, $this->id);

        $this->name = $name;
        $this->description = self::text($description) ?? '';
        $this->instruction = self::text($instruction);
        $code = $code === null ? '' : trim($code);
        $this->code = $code === '' ? null : $code;
        $this->categories = $categories;
        if ($this->code !== null && $affiliateLinkWithCode !== null && trim($affiliateLinkWithCode) !== '') {
            self::assertLink($affiliateLinkWithCode, $this->id);
            $this->affiliateLinkWithCode = $affiliateLinkWithCode;
        } else {
            $this->affiliateLinkWithCode = null;
        }
        $this->merchantName = self::text($merchantName);
        $this->adMarking = self::text($adMarking);
    }

    public function id(): CouponId
    {
        return $this->id;
    }

    public function merchantId(): MerchantId
    {
        return $this->merchantId;
    }

    public function merchantName(): ?string
    {
        return $this->merchantName;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * Условия применения (обычно совпадают с описанием).
     */
    public function instruction(): ?string
    {
        return $this->instruction;
    }

    /**
     * Промокод; null — акция без кода.
     */
    public function code(): ?string
    {
        return $this->code;
    }

    public function hasCode(): bool
    {
        return $this->code !== null;
    }

    public function kind(): CouponKind
    {
        return $this->kind;
    }

    /**
     * @return list<CouponCategory>
     */
    public function categories(): array
    {
        return $this->categories;
    }

    public function startsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    /**
     * ⚠️ Содержит токен XML API (`/ck/<токен>/<id>`).
     */
    public function affiliateLink(): string
    {
        return $this->affiliateLink;
    }

    /**
     * Ссылка, открывающая магазин с показом промокода; null — у купона нет кода. ⚠️ Содержит токен XML API.
     */
    public function affiliateLinkWithCode(): ?string
    {
        return $this->affiliateLinkWithCode;
    }

    /**
     * Маркировка рекламы «Реклама. Рекламодатель … erid …» — показывайте рядом со ссылкой.
     */
    public function adMarking(): ?string
    {
        return $this->adMarking;
    }

    public function hasStartedAt(\DateTimeInterface $moment): bool
    {
        return $moment >= $this->startsAt;
    }

    public function hasEndedAt(\DateTimeInterface $moment): bool
    {
        return $moment > $this->endsAt;
    }

    /**
     * Действует ли купон в момент: начало ≤ момент ≤ окончание.
     */
    public function isActiveAt(\DateTimeInterface $moment): bool
    {
        return $this->hasStartedAt($moment) && !$this->hasEndedAt($moment);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $values = get_object_vars($this);
        $values['affiliateLink'] = self::maskLink($this->affiliateLink);
        $values['affiliateLinkWithCode'] = $this->affiliateLinkWithCode === null ? null : self::maskLink($this->affiliateLinkWithCode);

        return $values;
    }

    /**
     * Ссылка купона без токена: сегмент пути после `/ck/` заменяется звёздочками (одна маска для дампов, CLI и MCP).
     *
     * @internal
     */
    public static function maskLink(string $link): string
    {
        return (string) preg_replace('~/ck/[^/?#]+/~', '/ck/***/', $link);
    }

    private static function assertLink(string $link, CouponId $id): void
    {
        if (preg_match('~^https?://[^\s/?#]+~i', $link) !== 1) {
            throw new InvalidArgumentException(sprintf('Купон %s: партнёрская ссылка должна начинаться с http(s)://', $id));
        }
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
