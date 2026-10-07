<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Promo;

use Webreboot\GdeSlon\Domain\Promo\Coupon;
use Webreboot\GdeSlon\Domain\Promo\CouponCategory;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponKind;
use Webreboot\GdeSlon\Domain\Promo\CouponList;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\SimpleXml;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\XmlRecordReader;

/**
 * Ответ coupons.xml → CouponList (docs/gdeslon-api/coupons.md).
 *
 * Документ читается потоково XmlRecordReader (без DTD и сети) в четыре прохода: справочники видов, категорий купонов и
 * магазинов, затем купоны. Вид у купона — название, его ID берётся из справочника; вид вне справочника, битые ID и даты
 * — пропуск записи с причиной. Причины не содержат ссылок: в них токен XML API. Даты — «Y-m-d H:i:s» по Москве.
 *
 * @internal
 */
final class CouponXmlParser
{
    private const LABEL = 'купонов';

    private const ROOT = 'gdeslon-coupons';

    private const TIMEZONE = 'Europe/Moscow';

    private readonly XmlRecordReader $reader;

    public function __construct()
    {
        $this->reader = new XmlRecordReader();
    }

    /**
     * @throws UnexpectedResponseException не XML купонов, нет справочника видов или контейнера купонов, все записи битые
     */
    public function parse(#[\SensitiveParameter] string $xml, CouponCriteria $criteria): CouponList
    {
        /** @var list<CouponKind> $kinds */
        $kinds = $this->reader->read($xml, self::LABEL, [self::ROOT, 'kinds', 'kind'], static fn (\SimpleXMLElement $kind): CouponKind
            => new CouponKind(self::positiveInt($kind, 'id'), self::required($kind, 'name')))->records();
        $kindsByName = [];
        foreach ($kinds as $kind) {
            $kindsByName[$kind->name()] ??= $kind;
        }

        // справочники имён — вспомогательные: битая запись не валит ответ (имя станет null), но попадает в skipped
        $dictionarySkipped = [];
        $merchants = self::dictionary($this->reader->read($xml, self::LABEL, [self::ROOT, 'merchants', 'merchant'], self::entry(...))->records(), 'справочник магазинов', $dictionarySkipped);
        $categories = self::dictionary($this->reader->read($xml, self::LABEL, [self::ROOT, 'coupon-categories', 'coupon-category'], self::entry(...))->records(), 'справочник категорий купонов', $dictionarySkipped);

        $position = 0;
        $result = $this->reader->read(
            $xml,
            self::LABEL,
            [self::ROOT, 'coupons', 'coupon'],
            static function (\SimpleXMLElement $coupon) use (&$position, $kindsByName, $categories, $merchants): Coupon {
                $position++;

                return self::coupon($coupon, $position, $kindsByName, $categories, $merchants);
            },
        );

        $coupons = [];
        $skipped = [...$dictionarySkipped, ...$result->skipped()];
        foreach ($result->records() as $coupon) {
            if (isset($coupons[$coupon->id()->value()])) {
                $skipped[] = sprintf('купон %d: повтор ID, оставлена первая запись', $coupon->id()->value());

                continue;
            }
            $coupons[$coupon->id()->value()] = $coupon;
        }

        return new CouponList($criteria, array_values($coupons), $kinds, $skipped);
    }

    /**
     * Ошибки из тела 400 `<gdeslon-coupons><merchant_id><list-item>…</list-item></merchant_id></gdeslon-coupons>`
     * (и `<detail>…</detail>`); null — тело другой формы.
     *
     * @return array<string, list<string>>|null
     */
    public function errors(#[\SensitiveParameter] string $xml): ?array
    {
        try {
            $records = $this->reader->read($xml, 'ошибок', [self::ROOT, '*'], static function (\SimpleXMLElement $field): array {
                $messages = array_map(static fn (\SimpleXMLElement $item): string => trim((string) $item), SimpleXml::children($field, 'list-item'));

                return [$field->getName(), $messages === [] ? [trim((string) $field)] : $messages];
            })->records();
        } catch (UnexpectedResponseException) {
            return null;
        }

        $errors = [];
        foreach ($records as [$field, $messages]) {
            $errors[$field] = $messages;
        }

        return $errors === [] ? null : $errors;
    }

    /**
     * @param array<string, CouponKind> $kinds      название → вид
     * @param array<int, string>        $categories ID → название
     * @param array<int, string>        $merchants  ID → название
     */
    private static function coupon(\SimpleXMLElement $coupon, int $position, array $kinds, array $categories, array $merchants): Coupon
    {
        $rawId = self::text($coupon, 'id');
        $label = $rawId !== null && preg_match('/^\d{1,18}\z/', trim($rawId)) === 1 ? sprintf('купон %s', trim($rawId)) : sprintf('купон №%d', $position);
        try {
            $kindName = trim((string) self::text($coupon, 'kind'));
            $kind = $kinds[$kindName] ?? throw new UnexpectedResponseException(sprintf('вид «%s» не из справочника видов', $kindName));

            $couponCategories = [];
            foreach (SimpleXml::children(SimpleXml::child($coupon, 'coupon-categories'), 'coupon-category') as $category) {
                $id = trim((string) SimpleXml::text($category, 'id'));
                if (preg_match('/^\d{1,18}\z/', $id) === 1 && (int) $id > 0) {
                    $couponCategories[] = new CouponCategory((int) $id, $categories[(int) $id] ?? null);
                }
            }

            $merchantId = self::positiveInt($coupon, 'merchant-id');

            return new Coupon(
                id: self::positiveInt($coupon, 'id'),
                merchantId: $merchantId,
                name: (string) self::text($coupon, 'name'),
                kind: $kind,
                startsAt: self::date($coupon, 'start-at'),
                endsAt: self::date($coupon, 'finish-at'),
                affiliateLink: self::text($coupon, 'url') ?? throw new UnexpectedResponseException('url: нет партнёрской ссылки'),
                merchantName: $merchants[$merchantId] ?? null,
                description: (string) self::text($coupon, 'description'),
                instruction: self::text($coupon, 'instruction'),
                code: self::text($coupon, 'code'),
                categories: $couponCategories,
                affiliateLinkWithCode: self::text($coupon, 'url-with-code'),
                adMarking: self::text($coupon, 'tagging_ads'),
            );
        } catch (UnexpectedResponseException | InvalidArgumentException $e) {
            throw new UnexpectedResponseException(sprintf('%s: %s', $label, $e->getMessage()));
        }
    }

    /**
     * @return array{int, string}|null null — битая запись справочника
     */
    private static function entry(\SimpleXMLElement $entry): ?array
    {
        try {
            return [self::positiveInt($entry, 'id'), self::required($entry, 'name')];
        } catch (UnexpectedResponseException) {
            return null;
        }
    }

    /**
     * @param list<array{int, string}|null> $entries
     * @param list<string>                  $skipped сюда — причина, если в справочнике есть битые записи
     *
     * @return array<int, string>
     */
    private static function dictionary(array $entries, string $label, array &$skipped): array
    {
        $dictionary = [];
        $broken = 0;
        foreach ($entries as $entry) {
            if ($entry === null) {
                $broken++;
            } else {
                $dictionary[$entry[0]] ??= $entry[1];
            }
        }
        if ($broken > 0) {
            // все битые — скорее всего сменился формат справочника
            $skipped[] = sprintf('%s: битых записей %d из %d', $label, $broken, count($entries));
        }

        return $dictionary;
    }

    private static function text(\SimpleXMLElement $element, string $name): ?string
    {
        $child = SimpleXml::child($element, $name);

        return $child === null ? null : (string) $child;
    }

    private static function required(\SimpleXMLElement $element, string $name): string
    {
        $value = trim((string) self::text($element, $name));
        if ($value === '') {
            throw new UnexpectedResponseException(sprintf('%s: пустое поле', $name));
        }

        return $value;
    }

    private static function positiveInt(\SimpleXMLElement $element, string $name): int
    {
        $value = trim((string) self::text($element, $name));
        if (preg_match('/^\d{1,18}\z/', $value) !== 1 || (int) $value === 0) {
            throw new UnexpectedResponseException(sprintf('%s — ожидалось положительное целое', $name));
        }

        return (int) $value;
    }

    private static function date(\SimpleXMLElement $element, string $name): \DateTimeImmutable
    {
        $value = trim((string) self::text($element, $name));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone(self::TIMEZONE));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new UnexpectedResponseException(sprintf('%s: ожидалась дата «Y-m-d H:i:s», получено «%s»', $name, substr($value, 0, 30)));
        }

        return $date;
    }
}
