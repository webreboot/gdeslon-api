<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Yml;

use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Domain\Catalog\ProductFeed;
use Webreboot\GdeSlon\Exception\FileException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Exception\UnsupportedFormatException;
use Webreboot\GdeSlon\Infrastructure\Api\Xml\XmlRecordReader;

/** @internal */
final class YmlProductFeed implements ProductFeed
{
    public const SKIPPED_LIMIT = 100;

    private const DOCTYPE = '<!DOCTYPE yml_catalog SYSTEM "shops.dtd">';

    private const PROLOG_BYTES = 4096;

    private const ROOT = 'yml_catalog';

    private const OFFERS_PATH = 'yml_catalog/shop/offers';

    private const CATEGORIES_PATH = 'yml_catalog/shop/categories';

    private const TIMEZONE = 'Europe/Moscow';

    // XML_ERR/XML_WAR_UNDECLARED_ENTITY: при внешнем shops.dtd не порча документа (запись разбирает fragment()), иначе документ не дочитан до корня
    private const UNDECLARED_ENTITY = [26, 27];

    /** @var list<string> */
    private array $skipped = [];

    private int $skippedCount = 0;

    private readonly OfferMapper $mapper;

    /**
     * @param array{int, int, int} $fingerprint
     */
    private function __construct(
        private readonly string $path,
        private readonly array $fingerprint,
        private readonly ?\DateTimeImmutable $generatedAt,
        private readonly CategoryTree $categories,
    ) {
        $this->mapper = new OfferMapper();
    }

    public static function open(string $path): self
    {
        $fingerprint = str_contains($path, '://') || !is_file($path) || !is_readable($path) ? null : self::fingerprint($path);
        if ($fingerprint === null) {
            throw new InvalidArgumentException('Файл выгрузки: ожидался путь к существующему локальному файлу');
        }
        $size = $fingerprint[0];
        $prolog = (string) file_get_contents($path, false, null, 0, self::PROLOG_BYTES);
        self::assertYml($prolog, $size);

        $doctypeAllowed = XmlRecordReader::allowedDoctypeInProlog($prolog, self::DOCTYPE);
        [$generatedAt, $categories] = self::withLibxml(static fn (): array => self::header($path, $size, $doctypeAllowed));

        return new self($path, $fingerprint, $generatedAt, $categories);
    }

    public function generatedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function categories(): CategoryTree
    {
        return $this->categories;
    }

    /**
     * @return \Generator<int, Offer>
     */
    public function offers(): \Generator
    {
        $this->skipped = [];
        $this->skippedCount = 0;
        $reader = new \XMLReader();
        /** @var array{path: list<string>, positioned: bool, rootClosed: bool, deferred: ?string} $state */
        $state = ['path' => [], 'positioned' => false, 'rootClosed' => false, 'deferred' => null];
        $current = self::fingerprint($this->path);
        if ($current === null || !is_readable($this->path)) {
            throw new FileException('Файл выгрузки недоступен: удалён или нет прав на чтение');
        }
        if ($current !== $this->fingerprint) {
            throw self::invalid($this->fingerprint[0], 'выгрузка изменилась после openFeed(), откройте её заново');
        }

        try {
            // без @ XMLReader::open() при сбое пишет PHP Warning, который фреймворки превращают в ErrorException
            if (!self::withLibxml(fn (): bool => @$reader->open($this->path, null, LIBXML_NONET))) {
                throw new FileException('Файл выгрузки недоступен: удалён или нет прав на чтение');
            }
            while (true) {
                $next = self::withLibxml(function () use ($reader, &$state): Offer|string|null {
                    return $this->nextOffer($reader, $state);
                });
                if ($next === null) {
                    return;
                }
                if (is_string($next)) {
                    $this->skip($next);

                    continue;
                }
                yield $next;
            }
        } finally {
            $reader->close();
        }
    }

    public function skipped(): array
    {
        return $this->skipped;
    }

    public function skippedCount(): int
    {
        return $this->skippedCount;
    }

    /**
     * @param array{path: list<string>, positioned: bool, rootClosed: bool, deferred: ?string} $state
     */
    private function nextOffer(\XMLReader $reader, array &$state): Offer|string|null
    {
        $size = $this->fingerprint[0];
        while ($state['positioned'] || $reader->read()) {
            $state['positioned'] = false;
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === 0) {
                $state['rootClosed'] = true;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            $state['path'] = array_slice($state['path'], 0, $reader->depth);
            $state['path'][] = $reader->name;
            if ($reader->name !== 'offer' || implode('/', array_slice($state['path'], 0, -1)) !== self::OFFERS_PATH) {
                continue;
            }

            $id = trim((string) $reader->getAttribute('id'));
            $xml = $reader->readOuterXml();
            self::remember($state['deferred'], $size);
            $state['positioned'] = $reader->next();
            self::remember($state['deferred'], $size);
            array_pop($state['path']);
            $record = self::fragment($xml);
            if ($record === null) {
                return sprintf('оффер %s: ошибка XML в записи', $id === '' ? 'без id' : '«' . $id . '»');
            }

            try {
                $offer = $this->mapper->map($record);
            } catch (UnexpectedResponseException $e) {
                return $e->getMessage();
            }

            return $offer->price()->isZero() ? sprintf('оффер «%s»: цена 0, оффер пропущен', $offer->id()) : $offer;
        }
        self::remember($state['deferred'], $size);
        if (!$state['rootClosed']) {
            throw self::invalid($size, $state['deferred'] ?? sprintf('документ оборван до закрытия «%s»', self::ROOT));
        }

        return null;
    }

    private function skip(string $reason): void
    {
        $this->skippedCount++;
        if (count($this->skipped) < self::SKIPPED_LIMIT) {
            $this->skipped[] = $reason;
        }
    }

    /**
     * @return array{?\DateTimeImmutable, CategoryTree}
     */
    private static function header(string $path, int $size, bool $doctypeAllowed): array
    {
        $reader = new \XMLReader();
        if (!@$reader->open($path, null, LIBXML_NONET)) {
            throw new FileException('Файл выгрузки недоступен: удалён или нет прав на чтение');
        }

        try {
            $generatedAt = null;
            $categories = null;
            $rootSeen = false;
            $path = [];
            $positioned = false;
            $deferred = null;
            while ($positioned || $reader->read()) {
                $positioned = false;
                if ($reader->nodeType === \XMLReader::DOC_TYPE && !$doctypeAllowed) {
                    throw self::invalid($size, 'неожиданный DOCTYPE');
                }
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if (!$rootSeen) {
                    if ($reader->name !== self::ROOT) {
                        throw self::invalid($size, sprintf('корневой элемент «%s» вместо «%s»', $reader->name, self::ROOT));
                    }
                    $rootSeen = true;
                    $generatedAt = self::date($reader->getAttribute('date'));
                }
                $path = array_slice($path, 0, $reader->depth);
                $path[] = $reader->name;
                $current = implode('/', $path);
                if ($current === self::OFFERS_PATH) {
                    self::remember($deferred, $size);

                    return [$generatedAt, $categories ?? new CategoryTree([])];
                }
                if ($current === self::CATEGORIES_PATH) {
                    $xml = $reader->readOuterXml();
                    self::remember($deferred, $size);
                    $positioned = $reader->next();
                    self::remember($deferred, $size);
                    $element = self::fragment($xml) ?? throw self::invalid($size, 'ошибка XML в категориях');
                    $categories = (new FeedCategoriesMapper())->map($element);
                    array_pop($path);
                }
            }
            self::remember($deferred, $size);

            throw self::invalid($size, $deferred ?? ($rootSeen ? sprintf('нет элемента «%s»', self::OFFERS_PATH) : sprintf('нет корневого элемента «%s»', self::ROOT)));
        } finally {
            $reader->close();
        }
    }

    private static function assertYml(#[\SensitiveParameter] string $prolog, int $size): void
    {
        if (trim($prolog) === '') {
            throw self::invalid($size, 'пустой файл');
        }
        if (str_starts_with($prolog, "PK\x03\x04")) {
            throw new UnsupportedFormatException('Выгрузка в zip-архиве: сначала распакуйте её (GdeSlon::unpackFeed())');
        }
        if (str_starts_with($prolog, "\x1F\x8B")) {
            throw new UnsupportedFormatException('Выгрузка сжата gzip: распакуйте её перед чтением');
        }
        $text = ltrim(str_starts_with($prolog, "\xEF\xBB\xBF") ? substr($prolog, 3) : $prolog);
        if (!str_starts_with($text, '<')) {
            $firstLine = strtok($text, "\r\n");
            if ($firstLine !== false && (str_contains($firstLine, ';') || str_contains($firstLine, "\t"))) {
                throw new UnsupportedFormatException('CSV-выгрузка не поддерживается: создайте в кабинете выгрузку в формате XML');
            }

            throw self::invalid($size, 'не XML');
        }
        if (preg_match('~<!DOCTYPE~i', $prolog) === 1 && !XmlRecordReader::allowedDoctypeInProlog($prolog, self::DOCTYPE)) {
            throw self::invalid($size, sprintf('неожиданный DOCTYPE: %s', XmlRecordReader::shownDoctype($prolog)));
        }
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        $value = trim($value ?? '');
        $timezone = new \DateTimeZone(self::TIMEZONE);
        foreach (['!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private static function withLibxml(\Closure $operation): mixed
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return $operation();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function fragment(#[\SensitiveParameter] string $xml): ?\SimpleXMLElement
    {
        foreach ([$xml, self::htmlEntitiesAsCharacters($xml)] as $candidate) {
            libxml_clear_errors();
            $element = simplexml_load_string($candidate, \SimpleXMLElement::class, LIBXML_NONET);
            $failed = $element === false || self::hasErrors();
            libxml_clear_errors();
            if (!$failed) {
                return $element;
            }
        }

        return null;
    }

    private static function htmlEntitiesAsCharacters(#[\SensitiveParameter] string $xml): string
    {
        return (string) preg_replace_callback('/&([A-Za-z][A-Za-z0-9]{1,31});/', static function (array $match): string {
            if (in_array($match[1], ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
                return $match[0];
            }
            $character = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $character === $match[0] ? $match[0] : htmlspecialchars($character, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }, $xml);
    }

    /**
     * @return array{int, int, int}|null
     */
    private static function fingerprint(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = is_file($path) ? @stat($path) : false;

        return $stat === false ? null : [$stat['size'], $stat['mtime'], $stat['ino']];
    }

    private static function hasErrors(): bool
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                return true;
            }
        }

        return false;
    }

    private static function remember(?string &$deferred, int $size): void
    {
        $error = self::assertNoErrors($size);
        $deferred ??= $error;
    }

    private static function assertNoErrors(int $size): ?string
    {
        $deferred = null;
        foreach (libxml_get_errors() as $error) {
            if ($error->level < LIBXML_ERR_ERROR) {
                continue;
            }
            $message = strtok(trim($error->message), "\n");
            $description = sprintf('ошибка XML в строке %d: %s', $error->line, $message === false ? '?' : $message);
            if (!in_array($error->code, self::UNDECLARED_ENTITY, true)) {
                libxml_clear_errors();

                throw self::invalid($size, $description);
            }
            $deferred ??= $description;
        }
        libxml_clear_errors();

        return $deferred;
    }

    private static function invalid(int $size, string $reason): UnexpectedResponseException
    {
        return new UnexpectedResponseException(sprintf('Выгрузка (%d байт): %s', $size, $reason));
    }
}
