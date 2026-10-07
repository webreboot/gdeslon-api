<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Postback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\CurlTransport;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\LocalHttpServer;

final class PostbackFromGlobalsTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789';

    private static LocalHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testGet(): void
    {
        $result = self::json(self::send(new HttpRequest('GET', self::$server->url('/postback') . '?' . Fixtures::read('postback/get-all-macros.query'), [], ['X-Gdeslon-Secret' => self::SECRET])));

        self::assertSame('query', $result['format']);
        self::assertExpected($result);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('posts')]
    public function testPost(string $body, array $headers, string $format): void
    {
        $result = self::json(self::send(HttpRequest::post(self::$server->url('/postback'), $body, ['X-Gdeslon-Secret' => self::SECRET] + $headers)));

        self::assertSame($format, $result['format']);
        self::assertExpected($result);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function posts(): iterable
    {
        yield 'форма' => [Fixtures::read('postback/form-all-macros.txt'), ['Content-Type' => 'application/x-www-form-urlencoded'], 'form'];
        yield 'JSON' => [Fixtures::read('postback/json-all-macros.json'), ['Content-Type' => 'application/json'], 'json'];
        yield 'XML' => [Fixtures::read('postback/xml-all-macros.xml'), ['Content-Type' => 'application/xml'], 'xml'];

        parse_str(Fixtures::read('postback/form-all-macros.txt'), $fields);
        $multipart = '';
        foreach ($fields as $name => $value) {
            $multipart .= "--b0undary\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . (is_string($value) ? $value : '') . "\r\n";
        }
        yield 'multipart' => [$multipart . "--b0undary--\r\n", ['Content-Type' => 'multipart/form-data; boundary=b0undary'], 'form'];
    }

    public function testWrongSecret(): void
    {
        $response = self::send(new HttpRequest('GET', self::$server->url('/postback') . '?merchant_id=1&state=3', [], ['X-Gdeslon-Secret' => 'wrong-secret-0123456789']));

        self::assertSame(401, $response->statusCode());
        self::assertStringNotContainsString(self::SECRET, $response->body());
        self::assertStringNotContainsString('wrong-secret-0123456789', $response->body());
    }

    private static function send(HttpRequest $request): HttpResponse
    {
        return (new CurlTransport())->send($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(HttpResponse $response): array
    {
        self::assertSame(200, $response->statusCode(), $response->body());
        $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function assertExpected(array $result): void
    {
        self::assertSame(2573, $result['merchant_id']);
        self::assertSame(3, $result['state']);
        self::assertSame('900001', $result['order_id']);
        self::assertSame(['1' => 'тест 1+2 &x=y', '2' => '"кавычки" <b>'], $result['sub_ids']);
        self::assertSame('123.45', $result['reward']);
        self::assertSame('Магазин «Тест» & Co', $result['offer_name']);
        self::assertSame([], $result['warnings']);
    }
}
