<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\XmlRecordReader;
use Webreboot\GdeSlon\Infrastructure\Yml\OfferMapper;

/** @internal */
final class OfferXmlParser
{
    public const DOCTYPE = '<!DOCTYPE yml_catalog SYSTEM "shops.dtd">';

    private const TOTAL_PATH = 'yml_catalog/info/documents_number';

    private const UNKNOWN_TOTAL = 1000000;

    private readonly XmlRecordReader $reader;

    private readonly OfferMapper $mapper;

    public function __construct()
    {
        $this->reader = new XmlRecordReader();
        $this->mapper = new OfferMapper();
    }

    public function parse(#[\SensitiveParameter] string $xml, SearchCriteria $criteria): SearchResult
    {
        $records = $this->reader->read(
            $xml,
            'поиска',
            ['yml_catalog', 'offers', 'offer'],
            fn (\SimpleXMLElement $offer): Offer => $this->mapper->map($offer),
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
}
