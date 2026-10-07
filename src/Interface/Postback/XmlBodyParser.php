<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/** @internal */
final class XmlBodyParser
{
    // XML_PARSE_IGNORE_ENC (в PHP нет константы): иначе на libxml 2.9 объявленная кодировка перебивает UTF-8
    private const IGNORE_DECLARED_ENCODING = 2097152;

    private const DOCTYPE_IN_PROLOG = '/^(?:\xEF\xBB\xBF)?\s*(?:(?:<\?.*?\?>|<!--.*?-->)\s*)*<!(?:DOCTYPE|ENTITY)/is';

    /** @return array<string, string|NonScalarValue> */
    public static function parse(string $body): array
    {
        if (trim($body) === '') {
            throw new InvalidPostbackException('Postback: пустое тело XML');
        }
        if (str_contains($body, "\0") || preg_match('//u', $body) !== 1) {
            throw new InvalidPostbackException('Postback: тело XML должно быть в UTF-8');
        }
        if (preg_match(self::DOCTYPE_IN_PROLOG, $body) === 1) {
            throw new InvalidPostbackException('Postback: DOCTYPE и сущности в XML запрещены');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return self::read($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, string|NonScalarValue> */
    private static function read(string $body): array
    {
        $reader = new \XMLReader();
        if (!$reader->XML($body, 'UTF-8', LIBXML_NONET | self::IGNORE_DECLARED_ENCODING)) {
            throw new InvalidPostbackException('Postback: тело не XML');
        }

        $fields = [];
        $current = null;
        $rootSeen = false;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                throw new InvalidPostbackException('Postback: DOCTYPE и сущности в XML запрещены');
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->depth === 0) {
                $rootSeen = true;
            } elseif ($reader->depth === 1) {
                $current = $reader->name;
                if (array_key_exists($current, $fields)) {
                    throw InvalidPostbackException::forField($current, sprintf('Postback: элемент «%s» повторяется', PostbackText::safe($current)));
                }
                $fields[$current] = $reader->isEmptyElement ? '' : $reader->readString();
            } elseif ($current !== null) {
                $fields[$current] = new NonScalarValue('вложенный элемент');
            }
        }

        $errors = libxml_get_errors();
        if ($errors !== [] || !$rootSeen) {
            throw new InvalidPostbackException(sprintf(
                'Postback: тело не XML%s',
                $errors === [] ? '' : sprintf(' (строка %d: %s)', $errors[0]->line, PostbackText::safe(trim($errors[0]->message))),
            ));
        }

        return $fields;
    }
}
