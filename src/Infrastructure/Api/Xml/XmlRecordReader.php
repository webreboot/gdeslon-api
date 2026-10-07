<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Xml;

use Webreboot\GdeSlon\Exception\UnexpectedResponseException;

/**
 * @internal
 */
final class XmlRecordReader
{
    /**
     * @template T
     *
     * @param list<string>                    $recordPath
     * @param callable(\SimpleXMLElement): T $map
     * @param list<string>                    $capture
     *
     * @return XmlRecords<T>
     */
    public function read(
        #[\SensitiveParameter]
        string $xml,
        string $label,
        array $recordPath,
        callable $map,
        array $capture = [],
        ?string $allowedDoctype = null,
    ): XmlRecords {
        if (trim($xml) === '') {
            throw self::invalid($label, $xml, 'пустой ответ');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return $this->readDocument($xml, $label, $recordPath, $map, $capture, $allowedDoctype);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @template T
     *
     * @param list<string>                    $recordPath
     * @param callable(\SimpleXMLElement): T $map
     * @param list<string>                    $capture
     *
     * @return XmlRecords<T>
     */
    private function readDocument(#[\SensitiveParameter] string $xml, string $label, array $recordPath, callable $map, array $capture, ?string $allowedDoctype): XmlRecords
    {
        $reader = new \XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET)) {
            throw self::invalid($label, $xml, 'не XML');
        }

        $recordDepth = count($recordPath) - 1;
        $containerPath = array_slice($recordPath, 0, -1);
        $containerSeen = false;
        $records = [];
        $skipped = [];
        $captured = [];
        /** @var list<string> $path */
        $path = [];
        $rootSeen = false;

        $more = $reader->read();
        while ($more) {
            if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                if ($allowedDoctype === null || !self::allowedDoctypeInProlog($xml, $allowedDoctype)) {
                    throw self::invalid($label, $xml, sprintf('неожиданный DOCTYPE: %s', self::shownDoctype($xml)));
                }
            } elseif ($reader->nodeType === \XMLReader::ELEMENT) {
                $path = array_slice($path, 0, $reader->depth);
                $path[] = $reader->name;
                $containerSeen = $containerSeen || $path === $containerPath;

                if (!$rootSeen) {
                    if ($reader->name !== $recordPath[0]) {
                        throw self::invalid($label, $xml, sprintf('корневой элемент «%s» вместо «%s»', $reader->name, $recordPath[0]));
                    }
                    $rootSeen = true;
                } elseif ($reader->depth === $recordDepth && self::isRecord($path, $recordPath)) {
                    $record = simplexml_load_string($reader->readOuterXml(), \SimpleXMLElement::class, LIBXML_NONET);
                    if ($record === false) {
                        break;
                    }
                    try {
                        $records[] = $map($record);
                    } catch (UnexpectedResponseException $e) {
                        $skipped[] = $e->getMessage();
                    }
                    $more = $reader->next();

                    continue;
                } elseif (in_array(implode('/', $path), $capture, true)) {
                    $captured[implode('/', $path)] = $reader->readString();
                }
            }
            $more = $reader->read();
        }

        $errors = libxml_get_errors();
        if ($errors !== []) {
            throw self::invalid($label, $xml, sprintf('ошибка XML в строке %d: %s', $errors[0]->line, trim($errors[0]->message)));
        }
        if (!$rootSeen) {
            throw self::invalid($label, $xml, sprintf('нет корневого элемента «%s»', $recordPath[0]));
        }
        if (!$containerSeen) {
            throw self::invalid($label, $xml, sprintf('нет элемента «%s»', implode('/', $containerPath)));
        }
        if ($records === [] && $skipped !== []) {
            throw self::invalid($label, $xml, sprintf('ни одна запись не разобрана (%d), первая: %s', count($skipped), $skipped[0]));
        }

        return new XmlRecords($records, $skipped, $captured);
    }

    private static function allowedDoctypeInProlog(string $xml, string $allowedDoctype): bool
    {
        return preg_match('~\A(?:\xEF\xBB\xBF)?\s*(?:<\?xml\s[^>]*\?>\s*)?' . preg_quote($allowedDoctype, '~') . '~', $xml) === 1;
    }

    private static function shownDoctype(string $xml): string
    {
        if (preg_match('~<!DOCTYPE[^\[>]{0,200}~i', $xml, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '?';
        }
        $head = (string) preg_replace('/[^\x20-\x7E]/', '?', rtrim($match[0][0]));
        $next = $xml[$match[0][1] + strlen($match[0][0])] ?? '';

        return $head . ($next === '[' ? ' […]>' : '>');
    }

    /**
     * @param list<string> $path
     * @param list<string> $recordPath
     */
    private static function isRecord(array $path, array $recordPath): bool
    {
        return end($recordPath) === '*'
            ? array_slice($path, 0, -1) === array_slice($recordPath, 0, -1)
            : $path === $recordPath;
    }

    private static function invalid(string $label, #[\SensitiveParameter] string $xml, string $reason): UnexpectedResponseException
    {
        return new UnexpectedResponseException(sprintf('Ответ %s (получено %d байт): %s', $label, strlen($xml), $reason));
    }
}
