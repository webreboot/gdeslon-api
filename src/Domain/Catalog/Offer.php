<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Оффер — товарное предложение магазина из поиска с готовой партнёрской ссылкой вебмастера.
 *
 * ID — строка из цифр: в API это 18–20 цифр (больше PHP_INT_MAX), и уникальность не гарантирована — не используйте его
 * как ключ. Тексты — без HTML-сущностей, переводы строк `\n`. Новые параметры добавляются только в конец.
 */
final class Offer
{
    private readonly string $name;

    private readonly ?string $description;

    private readonly ?string $vendor;

    private readonly ?string $model;

    private readonly ?string $adMarking;

    /**
     * @param Money|null  $charge     вознаграждение вебмастера за заказ в деньгах; «0» — API его не рассчитало
     * @param string|null $productUrl прямая ссылка на товар — не для трафика (заказ по ней не засчитывается)
     * @param string|null $adMarking  маркировка рекламы («Реклама. Рекламодатель … erid …»)
     */
    public function __construct(
        private readonly string $id,
        private readonly MerchantId $merchantId,
        string $name,
        private readonly Money $price,
        private readonly string $affiliateLink,
        private readonly ?Money $oldPrice = null,
        private readonly ?Money $charge = null,
        private readonly ?string $article = null,
        private readonly ?CategoryId $categoryId = null,
        private readonly bool $available = true,
        private readonly ?string $picture = null,
        private readonly ?string $thumbnail = null,
        private readonly ?string $originalPicture = null,
        ?string $description = null,
        ?string $vendor = null,
        ?string $model = null,
        private readonly ?string $productUrl = null,
        ?string $adMarking = null,
    ) {
        if (preg_match('/^\d{1,40}\z/', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('ID оффера должен состоять из цифр, получено «%s»', $id));
        }
        $name = self::trimmed($name);
        if ($name === '') {
            throw new InvalidArgumentException(sprintf('Пустое название оффера %s', $id));
        }
        if (preg_match('~^https?://[^\s/?#]+~i', $affiliateLink) !== 1) {
            throw new InvalidArgumentException(sprintf('Партнёрская ссылка оффера %s: ожидался адрес http(s)://', $id));
        }
        foreach (['старая цена' => $oldPrice, 'вознаграждение' => $charge] as $what => $money) {
            if ($money !== null && $money->currency() !== $price->currency()) {
                throw new InvalidArgumentException(sprintf('Оффер %s: %s в %s, а цена в %s', $id, $what, $money->currency(), $price->currency()));
            }
        }

        $this->name = $name;
        $this->description = self::text($description);
        $this->vendor = self::text($vendor);
        $this->model = self::text($model);
        $this->adMarking = self::text($adMarking);
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Магазин: тот же ID, что в GdeSlon::merchants().
     */
    public function merchantId(): MerchantId
    {
        return $this->merchantId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function affiliateLink(): string
    {
        return $this->affiliateLink;
    }

    public function oldPrice(): ?Money
    {
        return $this->oldPrice;
    }

    public function charge(): ?Money
    {
        return $this->charge;
    }

    public function article(): ?string
    {
        return $this->article;
    }

    /**
     * Товарная категория (GdeSlon::categories()); null — API её не указал.
     */
    public function categoryId(): ?CategoryId
    {
        return $this->categoryId;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function picture(): ?string
    {
        return $this->picture;
    }

    public function thumbnail(): ?string
    {
        return $this->thumbnail;
    }

    public function originalPicture(): ?string
    {
        return $this->originalPicture;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function vendor(): ?string
    {
        return $this->vendor;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function productUrl(): ?string
    {
        return $this->productUrl;
    }

    public function adMarking(): ?string
    {
        return $this->adMarking;
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : self::trimmed(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }

    /**
     * Обрезка по краям вместе с неразрывными пробелами: API отдаёт `&nbsp;` в текстах, после раскодирования это U+00A0.
     */
    private static function trimmed(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? trim($value);
    }
}
