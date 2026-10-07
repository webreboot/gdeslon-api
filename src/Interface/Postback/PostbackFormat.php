<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/**
 * Как пришли параметры postback: query (GET), форма (POST `params`), JSON или XML (POST `json`/`xml`).
 */
enum PostbackFormat: string
{
    case Query = 'query';
    case Form = 'form';
    case Json = 'json';
    case Xml = 'xml';
}
