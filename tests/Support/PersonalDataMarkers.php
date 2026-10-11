<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

final class PersonalDataMarkers
{
    public const ORDER = 'GS-ЗАКАЗ-ИВАНОВ-777';

    public const TOTAL = '987654.32';

    public const MAIL = 'ivanov.marker@example.com';

    public const PHONE = '+7 900 000-77-77';

    public const RECEIPT_MARK = 'ЧЕК-МАРКЕР-0001';

    public const RECEIPT = "%PDF-1.4\n" . self::RECEIPT_MARK;

    public const SUB = 'sub-ПЕТРОВ-42';

    public const IP = '203.0.113.77';

    public const UA = 'UA-МАРКЕР-9';

    private function __construct()
    {
    }
}
