<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\CategoryTariff;
use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Domain\Catalog\MerchantCategory;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\RateType;
use Webreboot\GdeSlon\Domain\Catalog\Tariff;
use Webreboot\GdeSlon\Domain\Catalog\TrafficType;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\SimpleXml;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\XmlRecordReader;

/**
 * Ответ shops.xml → список магазинов (docs/gdeslon-api/merchants.md).
 *
 * Документ читается XmlRecordReader по записям `<shop>` (безопасность XML — там); любой DOCTYPE — ошибка (в настоящем
 * ответе его нет).
 *
 * Битый документ целиком (не XML, обрезан, DOCTYPE, не тот корень) — ошибка. Битая запись отдельного магазина (нет id,
 * name, url, ставка не число, неизвестный rate_type…) — магазин пропускается, причина — в MerchantList::skipped()
 * (если не разобралась ни одна запись — это ошибка документа):
 * API меняется, и аномалия одной записи не должна лишать доступа ко всем магазинам. Пустые названия категорий и
 * тарифов категорий — null; тип трафика без названия отбрасывается.
 *
 * @internal
 */
final class MerchantXmlParser
{
    private readonly XmlRecordReader $reader;

    public function __construct()
    {
        $this->reader = new XmlRecordReader();
    }

    /**
     * @throws UnexpectedResponseException
     */
    public function parse(string $xml): MerchantList
    {
        $records = $this->reader->read($xml, 'магазинов', ['shops', 'shop'], fn (\SimpleXMLElement $shop): Merchant => $this->toMerchant($shop));

        $merchants = [];
        $skipped = $records->skipped();
        foreach ($records->records() as $merchant) {
            $id = $merchant->id()->value();
            if (isset($merchants[$id])) {
                $skipped[] = sprintf('магазин «%d»: повтор id, оставлена первая запись', $id);
            } else {
                $merchants[$id] = $merchant;
            }
        }

        return new MerchantList($merchants, $skipped);
    }

    private function toMerchant(\SimpleXMLElement $shop): Merchant
    {
        $rawId = SimpleXml::optional($shop, 'id');
        $label = $rawId === null ? 'без id' : '«' . $rawId . '»';
        if ($rawId === null || preg_match('/^\d{1,18}$/', $rawId) !== 1 || (int) $rawId === 0) {
            throw self::invalidRecord($label, sprintf('поле id должно быть положительным целым, получено «%s»', $rawId ?? ''));
        }

        try {
            return new Merchant(
                new MerchantId((int) $rawId),
                self::required($shop, 'name', $label),
                self::required($shop, 'url', $label),
                shortDescription: SimpleXml::text($shop, 'short-description') ?? '',
                description: SimpleXml::text($shop, 'description') ?? '',
                conditions: SimpleXml::text($shop, 'conditions') ?? '',
                logoUrl: SimpleXml::optional($shop, 'logo-file-name'),
                country: SimpleXml::optional($shop, 'country'),
                kind: SimpleXml::optional($shop, 'kind'),
                green: self::boolean(SimpleXml::text($shop, 'is-green') ?? 'false', 'is-green', $label),
                commissionSummary: SimpleXml::text($shop, 'gs-commission-mark'),
                categories: $this->categories($shop, $label),
                affiliateLink: SimpleXml::optional($shop, 'affiliate-link'),
                trafficTypes: $this->trafficTypes($shop, $label),
                tariffs: $this->tariffs($shop, $label),
                categoryTariffs: $this->categoryTariffs($shop, $label),
                adMarking: SimpleXml::text($shop, 'tagging_ads'),
            );
        } catch (InvalidArgumentException $e) {
            throw self::invalidRecord($label, $e->getMessage(), $e);
        }
    }

    /**
     * @return list<MerchantCategory>
     */
    private function categories(\SimpleXMLElement $shop, string $label): array
    {
        $categories = [];
        foreach (SimpleXml::children(SimpleXml::child($shop, 'categories'), 'category') as $category) {
            $id = SimpleXml::text($category, 'id');
            if ($id === null || preg_match('/^\d{1,18}$/', $id) !== 1) {
                throw self::invalidRecord($label, sprintf('category: id должен быть целым, получено «%s»', $id ?? ''));
            }
            $categories[] = new MerchantCategory((int) $id, SimpleXml::text($category, 'name'));
        }

        return $categories;
    }

    /**
     * @return list<TrafficType>
     */
    private function trafficTypes(\SimpleXMLElement $shop, string $label): array
    {
        $types = [];
        foreach (SimpleXml::children(SimpleXml::child($shop, 'traffic-types'), 'traffic-type') as $type) {
            $name = SimpleXml::text($type, 'name');
            $allowed = self::boolean(SimpleXml::text($type, 'allowed') ?? '', 'allowed', $label);
            if ($name !== null) {
                // тип трафика без названия ничего не сообщает — отбрасывается
                $types[] = new TrafficType($name, $allowed);
            }
        }

        return $types;
    }

    /**
     * @return list<Tariff>
     */
    private function tariffs(\SimpleXMLElement $shop, string $label): array
    {
        $tariffs = [];
        foreach (SimpleXml::children(SimpleXml::child($shop, 'tariffs'), 'tariff') as $tariff) {
            $id = SimpleXml::attribute($tariff, 'id') ?? '';
            $rateType = RateType::tryFrom(SimpleXml::attribute($tariff, 'rate_type') ?? '')
                ?? throw self::invalidRecord($label, sprintf('тариф «%s»: неизвестный rate_type «%s»', $id, SimpleXml::attribute($tariff, 'rate_type') ?? ''));
            $rate = trim((string) $tariff);
            if ($rate === '') {
                throw self::invalidRecord($label, sprintf('тариф «%s» без ставки', $id));
            }

            $tariffs[] = new Tariff(
                $id,
                SimpleXml::attribute($tariff, 'title'),
                $rateType,
                $rate,
                SimpleXml::split(SimpleXml::attribute($tariff, 'traffic_categories'), ','),
                SimpleXml::split(SimpleXml::attribute($tariff, 'category_name'), ';'),
            );
        }

        return $tariffs;
    }

    /**
     * @return list<CategoryTariff>
     */
    private function categoryTariffs(\SimpleXMLElement $shop, string $label): array
    {
        $tariffs = [];
        foreach (SimpleXml::children(SimpleXml::child($shop, 'tariffs'), 'tariff-category') as $tariff) {
            $categoryId = SimpleXml::attribute($tariff, 'category_id') ?? '';
            if (preg_match('/^\d{1,18}$/', $categoryId) !== 1) {
                throw self::invalidRecord($label, sprintf('tariff-category: category_id должен быть целым, получено «%s»', $categoryId));
            }
            $tariffs[] = new CategoryTariff(
                (int) $categoryId,
                SimpleXml::attribute($tariff, 'name'),
                self::boolean(SimpleXml::attribute($tariff, 'is_percent') ?? 'false', 'is_percent', $label),
                trim((string) $tariff),
            );
        }

        return $tariffs;
    }

    private static function required(\SimpleXMLElement $element, string $name, string $label): string
    {
        return SimpleXml::optional($element, $name) ?? throw self::invalidRecord($label, sprintf('нет поля %s', $name));
    }

    private static function boolean(string $value, string $field, string $label): bool
    {
        return SimpleXml::boolean($value)
            ?? throw self::invalidRecord($label, sprintf('поле %s должно быть логическим, получено «%s»', $field, $value));
    }

    private static function invalidRecord(string $label, string $reason, ?\Throwable $previous = null): UnexpectedResponseException
    {
        return new UnexpectedResponseException(sprintf('магазин %s: %s', $label, $reason), $previous);
    }

}
