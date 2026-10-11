<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\TraceSecrets;

final class TraceSecretsTest extends TestCase
{
    use TraceSecrets;

    public function testFakeTransportFrameIsNotPackageFrame(): void
    {
        self::requireSensitiveParameter();
        $transport = new FakeHttpTransport();
        $e = self::catchWithTraceArgs(
            static fn () => $transport->send(HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['_gs_at' => 'trace-token'])),
            \LogicException::class,
        );

        try {
            self::assertTraceHidesSecrets($e, 'trace-token');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('впустую', $failure->getMessage());

            return;
        }

        self::fail('Кадр Testing\FakeHttpTransport::send засчитан как кадр пакета');
    }
}
