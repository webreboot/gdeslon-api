<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Domain\Shared\Currency;
use Webreboot\GdeSlon\Domain\Shared\HttpUrl;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\SimpleXml;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\XmlRecordReader;

/** @internal */
final class OfferXmlParser
{
    public const DOCTYPE = '<!DOCTYPE yml_catalog SYSTEM "shops.dtd">';

    private const TOTAL_PATH = 'yml_catalog/info/documents_number';

    private const UNKNOWN_TOTAL = 1000000;

    private readonly XmlRecordReader $reader;

    public function __construct()
    {
        $this->reader = new XmlRecordReader();
    }

    public function parse(#[\SensitiveParameter] string $xml, SearchCriteria $criteria): SearchResult
    {
        $records = $this->reader->read(
            $xml,
            'поиска',
            ['yml_catalog', 'offers', 'offer'],
            fn (\SimpleXMLElement $offer): Offer => $this->toOffer($offer),
            [self::TOTAL_PATH],
            self::DOCTYPE,
        );

        $offers = [];
        $skipped = $records->skipped();
        foreach ($records->records() as $offer) {
            if ($offer->price()->isZero()) {
                $skipped[] = sprintf('оффер «%s»: цена 0, оффер пропущен', $offer->id());
            } else {
                $offers[] = $offer;
            }
        }

        return new SearchResult($criteria, $offers, self::total(
            $records->captured(self::TOTAL_PATH),
            $records->records() === [] && $records->skipped() === [] && $criteria->page() === 1,
        ), $skipped);
    }

    private function toOffer(\SimpleXMLElement $offer): Offer
    {
        $id = trim(SimpleXml::attribute($offer, 'id') ?? '');
        $label = $id === '' ? 'без id' : '«' . $id . '»';
        if (preg_match('/^\d{1,40}$/', $id) !== 1) {
            throw self::invalid($label, sprintf('атрибут id должен состоять из цифр, получено «%s»', $id));
        }

        $merchantId = self::positiveInt(SimpleXml::attribute($offer, 'merchant_id'), 'merchant_id', $label);
        $merchantElement = SimpleXml::optional($offer, 'merchant_id');
        if ($merchantElement !== null && $merchantElement !== (string) $merchantId) {
            throw self::invalid($label, sprintf('merchant_id атрибута (%d) и элемента (%s) различаются', $merchantId, $merchantElement));
        }

        $currency = self::currency($offer, $label);

        try {
            return new Offer(
                $id,
                new MerchantId($merchantId),
                SimpleXml::decodedText($offer, 'name') ?? throw self::invalid($label, 'нет поля name'),
                self::money($offer, 'price', $currency, $label) ?? throw self::invalid($label, 'нет поля price'),
                SimpleXml::optional($offer, 'url') ?? throw self::invalid($label, 'нет поля url'),
                oldPrice: self::money($offer, 'oldprice', $currency, $label),
                charge: self::charge($offer, $currency, $label),
                article: self::blankToNull(SimpleXml::attribute($offer, 'article')),
                categoryId: self::category($offer, $label),
                available: self::available($offer, $label),
                picture: HttpUrl::orNull(SimpleXml::optional($offer, 'picture')),
                thumbnail: HttpUrl::orNull(SimpleXml::optional($offer, 'thumbnail')),
                originalPicture: HttpUrl::orNull(SimpleXml::optional($offer, 'original_picture')),
                description: SimpleXml::decodedText($offer, 'description'),
                vendor: SimpleXml::decodedText($offer, 'vendor'),
                model: SimpleXml::decodedText($offer, 'model'),
                productUrl: HttpUrl::orNull(SimpleXml::optional($offer, 'destination-url-do-not-send-traffic')),
                adMarking: self::adMarking($offer),
            );
        } catch (InvalidArgumentException $e) {
            throw self::invalid($label, $e->getMessage(), $e);
        }
    }

    private static function adMarking(\SimpleXMLElement $offer): ?string
    {
        $taggingAds = SimpleXml::child($offer, 'tagging_ads');

        return $taggingAds === null ? null : SimpleXml::decodedText($taggingAds, 'info');
    }

    private static function currency(\SimpleXMLElement $offer, string $label): string
    {
        $code = SimpleXml::optional($offer, 'currencyId') ?? throw self::invalid($label, 'нет поля currencyId');

        try {
            return Currency::normalize($code);
        } catch (InvalidArgumentException $e) {
            throw self::invalid($label, sprintf('поле currencyId: %s', $e->getMessage()), $e);
        }
    }

    private static function charge(\SimpleXMLElement $offer, string $currency, string $label): ?Money
    {
        $charge = self::money($offer, 'charge', $currency, $label);

        return $charge !== null && $charge->isZero() ? null : $charge;
    }

    private static function money(\SimpleXMLElement $offer, string $field, string $currency, string $label): ?Money
    {
        $amount = SimpleXml::optional($offer, $field);
        if ($amount === null) {
            return null;
        }

        try {
            return new Money($amount, $currency);
        } catch (InvalidArgumentException $e) {
            throw self::invalid($label, sprintf('поле %s: %s', $field, $e->getMessage()), $e);
        }
    }

    private static function category(\SimpleXMLElement $offer, string $label): ?CategoryId
    {
        $value = self::blankToNull(SimpleXml::attribute($offer, 'gs_category_id'));

        return $value === null ? null : new CategoryId(self::positiveInt($value, 'gs_category_id', $label));
    }

    private static function available(\SimpleXMLElement $offer, string $label): bool
    {
        $value = SimpleXml::attribute($offer, 'available');
        if ($value === null) {
            return true;
        }

        return SimpleXml::boolean($value)
            ?? throw self::invalid($label, sprintf('атрибут available должен быть логическим, получено «%s»', $value));
    }

    private static function positiveInt(?string $value, string $field, string $label): int
    {
        $value = trim($value ?? '');
        if (preg_match('/^\d{1,18}$/', $value) !== 1 || (int) $value === 0) {
            throw self::invalid($label, sprintf('%s должен быть положительным целым, получено «%s»', $field, $value));
        }

        return (int) $value;
    }

    private static function total(?string $documentsNumber, bool $emptyFirstPage): ?int
    {
        if ($documentsNumber === null || preg_match('/^\d{1,18}$/', trim($documentsNumber)) !== 1) {
            return null;
        }
        $total = (int) trim($documentsNumber);
        if ($total !== self::UNKNOWN_TOTAL) {
            return $total;
        }

        return $emptyFirstPage ? 0 : null;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }

    private static function invalid(string $label, string $reason, ?\Throwable $previous = null): UnexpectedResponseException
    {
        return new UnexpectedResponseException(sprintf('оффер %s: %s', $label, $reason), $previous);
    }
}
