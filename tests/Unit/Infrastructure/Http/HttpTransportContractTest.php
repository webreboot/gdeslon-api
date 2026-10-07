<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;

final class HttpTransportContractTest extends TestCase
{
    public function testContractRequiresBody(): void
    {
        // свой транспорт без тела молча отправил бы POST заказов без фильтров (API ответит 200 [])
        $doc = (string) (new \ReflectionClass(HttpTransport::class))->getDocComment();

        self::assertStringContainsString('HttpRequest::body()', $doc);
        self::assertStringContainsString('HttpRequest::timeout()', $doc);
        self::assertStringContainsString('Connection: close', $doc);
    }
}
