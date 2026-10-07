<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Postback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Sales\Conversion;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Interface\Postback\HeaderSecret;
use Webreboot\GdeSlon\Interface\Postback\InvalidPostbackException;
use Webreboot\GdeSlon\Interface\Postback\PostbackAuthenticationException;
use Webreboot\GdeSlon\Interface\Postback\PostbackFields;
use Webreboot\GdeSlon\Interface\Postback\PostbackFormat;
use Webreboot\GdeSlon\Interface\Postback\PostbackReceiver;
use Webreboot\GdeSlon\Interface\Postback\PostbackRequest;
use Webreboot\GdeSlon\Interface\Postback\QueryStringParser;
use Webreboot\GdeSlon\Interface\Postback\ReceivedPostback;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class PostbackReceiverTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789';

    public function testGetQuery(): void
    {
        $postback = self::receive(new PostbackRequest('GET', self::headers(), Fixtures::read('postback/get-all-macros.query')));

        self::assertSame(PostbackFormat::Query, $postback->format());
        self::assertSame([], $postback->warnings());
        self::assertExpectedConversion($postback->conversion());
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('postBodies')]
    public function testPostBodies(string $fixture, array $headers, PostbackFormat $format): void
    {
        $postback = self::receive(new PostbackRequest('POST', self::headers($headers), '', Fixtures::read('postback/' . $fixture)));

        self::assertSame($format, $postback->format());
        self::assertSame([], $postback->warnings());
        self::assertExpectedConversion($postback->conversion());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, PostbackFormat}>
     */
    public static function postBodies(): iterable
    {
        yield 'форма' => ['form-all-macros.txt', ['Content-Type' => 'application/x-www-form-urlencoded'], PostbackFormat::Form];
        yield 'форма без Content-Type' => ['form-all-macros.txt', [], PostbackFormat::Form];
        yield 'JSON строками' => ['json-all-macros.json', ['Content-Type' => 'application/json; charset=utf-8'], PostbackFormat::Json];
        yield 'JSON числами' => ['json-numbers.json', ['Content-Type' => 'application/json'], PostbackFormat::Json];
        yield 'JSON как text/plain' => ['json-all-macros.json', ['Content-Type' => 'text/plain'], PostbackFormat::Json];
        yield 'XML application/xml' => ['xml-all-macros.xml', ['Content-Type' => 'application/xml'], PostbackFormat::Xml];
        yield 'XML text/xml' => ['xml-all-macros.xml', ['Content-Type' => 'text/xml; charset=UTF-8'], PostbackFormat::Xml];
        yield 'XML без Content-Type' => ['xml-all-macros.xml', [], PostbackFormat::Xml];
    }

    public function testMultipartForm(): void
    {
        $form = QueryStringParser::parse(Fixtures::read('postback/form-all-macros.txt'));

        $postback = self::receive(new PostbackRequest('POST', self::headers(['Content-Type' => 'multipart/form-data; boundary=x']), '', '', $form));

        self::assertSame(PostbackFormat::Form, $postback->format());
        self::assertExpectedConversion($postback->conversion());
    }

    public function testPostWithEmptyBodyUsesQuery(): void
    {
        $postback = self::receive(new PostbackRequest('POST', self::headers(), Fixtures::read('postback/get-all-macros.query')));

        self::assertSame(PostbackFormat::Query, $postback->format());
        self::assertExpectedConversion($postback->conversion());
    }

    public function testWrongBodyForContentType(): void
    {
        $this->expectException(InvalidPostbackException::class);
        $this->expectExceptionMessage('JSON');

        self::receive(new PostbackRequest('POST', self::headers(['Content-Type' => 'application/json']), '', Fixtures::read('postback/xml-all-macros.xml')));
    }

    public function testUnsupportedContentType(): void
    {
        try {
            self::receive(new PostbackRequest('POST', self::headers(['Content-Type' => 'text/html']), '', '<html></html>'));
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(415, $e->responseStatus());
        }
    }

    public function testSecretIsCheckedFirst(): void
    {
        try {
            self::receive(new PostbackRequest('POST', ['X-Gdeslon-Secret' => 'wrong-secret-0123456789'], '', '{broken'));
            self::fail('Ожидалось исключение');
        } catch (PostbackAuthenticationException $e) {
            self::assertSame(401, $e->responseStatus());
        }

        $unprotected = new PostbackReceiver(null);
        self::assertSame(2573, $unprotected->receive(new PostbackRequest('GET', [], 'merchant_id=2573&state=3'))->conversion()->merchantId()->value());
    }

    public function testMethodAndSize(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
            try {
                self::receive(new PostbackRequest($method, self::headers(), 'merchant_id=1&state=3'));
                self::fail('Ожидалось исключение: ' . $method);
            } catch (InvalidPostbackException $e) {
                self::assertSame(405, $e->responseStatus());
            }
        }

        try {
            (new PostbackReceiver(new HeaderSecret('X-Gdeslon-Secret', self::SECRET), maxBodyBytes: 100))
                ->receive(new PostbackRequest('POST', self::headers(), '', str_repeat('x', 101)));
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(413, $e->responseStatus());
        }
    }

    public function testFaqScreenshotWithCustomNames(): void
    {
        $receiver = new PostbackReceiver(
            new HeaderSecret('X-Gdeslon-Secret', self::SECRET),
            new PostbackFields(['order_id' => 'someOrderId', 'profit' => 'myProfit', 'click_id' => 'clickId']),
        );

        $postback = $receiver->receive(new PostbackRequest('GET', self::headers(), Fixtures::read('postback/faq-screenshot.query')));

        self::assertSame('1234567', $postback->conversion()->merchantOrderNumber());
        self::assertSame(OrderState::Confirmed, $postback->conversion()->state());
        self::assertNull($postback->conversion()->reward());
        self::assertNull($postback->conversion()->clickId());
        self::assertCount(1, $postback->warnings());
        self::assertStringContainsString('myProfit', $postback->warnings()[0]);
        self::assertSame('', $postback->parameter('clickId'));
        self::assertSame('eewrwer', $postback->parameter('myProfit'));
    }

    public function testRawParameters(): void
    {
        $postback = self::receive(new PostbackRequest('POST', self::headers(['Content-Type' => 'application/json']), '', '{"merchant_id":1,"state":3,"token":"tok","action_ip":"203.0.113.5","list":[1]}'));

        self::assertSame('tok', $postback->parameter('token'));
        self::assertSame('203.0.113.5', $postback->parameter('action_ip'));
        self::assertNull($postback->parameter('list'), 'нескалярное значение');
        self::assertNull($postback->parameter('missing'));
    }

    private static function receive(PostbackRequest $request): ReceivedPostback
    {
        return (new PostbackReceiver(new HeaderSecret('X-Gdeslon-Secret', self::SECRET)))->receive($request);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private static function headers(array $extra = []): array
    {
        return ['X-Gdeslon-Secret' => self::SECRET] + $extra;
    }

    private static function assertExpectedConversion(Conversion $conversion): void
    {
        self::assertSame(2573, $conversion->merchantId()->value());
        self::assertSame(OrderState::Confirmed, $conversion->state());
        self::assertSame('900001', $conversion->orderId()?->value());
        self::assertSame('A-100/7#1', $conversion->merchantOrderNumber());
        self::assertSame([1 => 'тест 1+2 &x=y', 2 => '"кавычки" <b>'], $conversion->subIds());
        self::assertSame('123.45', $conversion->reward());
        self::assertContains($conversion->orderSum(), ['1999.90', '1999.9'], 'JSON-числом теряется хвостовой ноль');
        self::assertSame('25.5', $conversion->priceInCurrency());
        self::assertSame('USD', $conversion->currency());
        self::assertSame('2026-10-07T12:34:56+03:00', $conversion->clickedAt()?->format('Y-m-d\TH:i:sP'));
        self::assertNull($conversion->actionAt());
        self::assertSame('Mozilla/5.0 (X11) "q" <x> \ end', $conversion->userAgent());
        self::assertSame('Магазин «Тест» & Co', $conversion->offerName());
        self::assertSame('abc123', $conversion->clickId());
        self::assertSame('gdeslon:900001:3', $conversion->deduplicationKey());
    }

    public function testUtf16XmlIsRejected(): void
    {
        foreach (['xml-xxe-utf16be.xml', 'xml-xxe-utf16le.xml'] as $fixture) {
            try {
                self::receive(new PostbackRequest('POST', self::headers(['Content-Type' => 'application/xml']), '', Fixtures::read('postback/' . $fixture)));
                self::fail('Ожидалось исключение: ' . $fixture);
            } catch (InvalidPostbackException $e) {
                self::assertSame(400, $e->responseStatus());
            }
        }
    }

    public function testMultipartWithRawBodyUsesParsedForm(): void
    {
        $form = QueryStringParser::parse(Fixtures::read('postback/form-all-macros.txt'));
        $raw = "--XX\r\nContent-Disposition: form-data; name=\"merchant_id\"\r\n\r\n2573\r\n--XX--\r\n";

        $postback = self::receive(new PostbackRequest('POST', self::headers(['Content-Type' => 'multipart/form-data; boundary=XX']), '', $raw, $form));

        self::assertSame(PostbackFormat::Form, $postback->format());
        self::assertExpectedConversion($postback->conversion());

        try {
            self::receive(new PostbackRequest('POST', self::headers(['Content-Type' => 'multipart/form-data; boundary=XX']), '', $raw));
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertSame(400, $e->responseStatus());
            self::assertStringContainsString('form', $e->getMessage());
        }
    }

    public function testRequestIsHiddenFromTraces(): void
    {
        try {
            self::receive(new PostbackRequest('GET', self::headers(), 'state=3'));
            self::fail('Ожидалось исключение');
        } catch (InvalidPostbackException $e) {
            self::assertStringNotContainsString(self::SECRET, print_r($e->getTrace(), true));
        }
    }

    public function testSecretIsHiddenFromTraceArguments(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('#[\SensitiveParameter] действует с PHP 8.2');
        }

        $requests = [
            '415' => new PostbackRequest('POST', self::headers(['Content-Type' => 'text/csv']), '', 'a,b'),
            '400 XML' => new PostbackRequest('POST', self::headers(['Content-Type' => 'application/xml']), '', '<r>'),
            '400 JSON' => new PostbackRequest('POST', self::headers(['Content-Type' => 'application/json']), '', '{"state":"3","state":"4"}'),
            '401' => new PostbackRequest('GET', ['X-Gdeslon-Secret' => [self::SECRET, 'x']], 'merchant_id=1&state=3'),
        ];
        foreach ($requests as $case => $request) {
            try {
                self::receive($request);
                self::fail("Ожидалось исключение: {$case}");
            } catch (\Webreboot\GdeSlon\Interface\Postback\PostbackException $e) {
                $frames = array_filter($e->getTrace(), static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Webreboot\\GdeSlon\\Interface\\'));
                self::assertNotSame([], $frames, (string) $case);
                self::assertStringNotContainsString(self::SECRET, var_export($frames, true), (string) $case);
            }
        }
    }
}
