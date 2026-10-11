<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Domain\Catalog\AffiliateLink;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Shared\AdMarking;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

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

    private readonly AffiliateLink $affiliateLink;

    private readonly ?AffiliateLink $affiliateLinkWithCode;

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
        #[\SensitiveParameter]
        string|AffiliateLink $affiliateLink,
        ?string $merchantName = null,
        string $description = '',
        ?string $instruction = null,
        ?string $code = null,
        array $categories = [],
        #[\SensitiveParameter]
        string|AffiliateLink|null $affiliateLinkWithCode = null,
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
        $this->affiliateLink = self::toLink($affiliateLink, $this->id);

        $this->name = $name;
        $this->description = self::text($description) ?? '';
        $this->instruction = self::text($instruction);
        $code = $code === null ? '' : trim($code);
        $this->code = $code === '' ? null : $code;
        $this->categories = $categories;
        $this->affiliateLinkWithCode = $this->code === null || $affiliateLinkWithCode === null || (is_string($affiliateLinkWithCode) && trim($affiliateLinkWithCode) === '')
            ? null
            : self::toLink($affiliateLinkWithCode, $this->id);
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

    public function instruction(): ?string
    {
        return $this->instruction;
    }

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

    public function affiliateLink(): string
    {
        return $this->affiliateLink->url();
    }

    public function affiliateLinkWithCode(): ?string
    {
        return $this->affiliateLinkWithCode?->url();
    }

    public function link(): AffiliateLink
    {
        return $this->affiliateLink;
    }

    public function linkWithCode(): ?AffiliateLink
    {
        return $this->affiliateLinkWithCode;
    }

    public function adMarking(): ?string
    {
        return $this->adMarking;
    }

    public function erid(): ?string
    {
        return AdMarking::erid($this->adMarking) ?? $this->affiliateLink->erid();
    }

    public function hasStartedAt(\DateTimeInterface $moment): bool
    {
        return $moment >= $this->startsAt;
    }

    public function hasEndedAt(\DateTimeInterface $moment): bool
    {
        return $moment > $this->endsAt;
    }

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
        $values['affiliateLink'] = $this->affiliateLink->masked();
        $values['affiliateLinkWithCode'] = $this->affiliateLinkWithCode?->masked();

        return $values;
    }

    private static function toLink(#[\SensitiveParameter] string|AffiliateLink $link, CouponId $id): AffiliateLink
    {
        if ($link instanceof AffiliateLink) {
            return $link;
        }

        try {
            return AffiliateLink::fromString($link);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('Купон %s: партнёрская ссылка должна начинаться с http(s):// и быть без пробелов и управляющих символов', $id));
        }
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
