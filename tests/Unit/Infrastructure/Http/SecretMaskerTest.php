<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\SecretMasker;

final class SecretMaskerTest extends TestCase
{
    #[DataProvider('urls')]
    public function testMaskUrl(string $url, string $expected): void
    {
        self::assertSame($expected, SecretMasker::maskUrl($url));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'токен XML API' => [
            'https://api.gdeslon.ru/api/search.xml?_gs_at=abc123&l=2',
            'https://api.gdeslon.ru/api/search.xml?_gs_at=***&l=2',
        ];
        yield 'api_token в конце, регистр' => [
            'https://www.gdeslon.ru/api/users/shops.json?x=1&API_TOKEN=abc123',
            'https://www.gdeslon.ru/api/users/shops.json?x=1&API_TOKEN=***',
        ];
        yield 'несколько секретов' => [
            'https://h/p?token=a1&api_key=b2&key=c3&password=d4&keep=e5',
            'https://h/p?token=***&api_key=***&key=***&password=***&keep=e5',
        ];
        yield 'не трогает похожие имена' => [
            'https://h/p?monkey=1&tokens=2&sub_id=3',
            'https://h/p?monkey=1&tokens=2&sub_id=3',
        ];
        yield 'userinfo' => ['https://user:secret@gdeslon.ru/api/orders/', 'https://***@gdeslon.ru/api/orders/'];
        yield 'без секретов' => [
            'https://api.gdeslon.ru/gdeslon-categories.json',
            'https://api.gdeslon.ru/gdeslon-categories.json',
        ];
        yield 'кириллица в query' => [
            'https://h/s?q=%D0%BF%D0%BB%D0%B0%D1%82%D1%8C%D0%B5&_gs_at=abc',
            'https://h/s?q=%D0%BF%D0%BB%D0%B0%D1%82%D1%8C%D0%B5&_gs_at=***',
        ];
    }

    public function testMaskTextHidesTokensEchoedInResponseBody(): void
    {
        $body = "<!DOCTYPE html>\n<pre>Cannot GET /api/search.xml?q=платье&_gs_at=abc123&l=2</pre>";

        $masked = SecretMasker::maskText($body);

        self::assertStringNotContainsString('abc123', $masked);
        self::assertStringContainsString('Cannot GET /api/search.xml?q=платье&_gs_at=***&l=2', $masked);
    }

    public function testSecretValuesFromAuthorization(): void
    {
        $basic = base64_encode('1234:test-key');

        self::assertSame(
            [$basic, 'test-key'],
            SecretMasker::secretValues(HttpRequest::post('https://h/', '{}', ['authorization' => 'Basic ' . $basic])),
        );
        self::assertSame(['bearer-token'], SecretMasker::secretValues(HttpRequest::get('https://h/', [], ['Authorization' => 'Bearer bearer-token'])));
        self::assertSame(
            ['query-token', 'bearer-token'],
            SecretMasker::secretValues(HttpRequest::get('https://h/', ['_gs_at' => 'query-token'], ['Authorization' => 'Bearer bearer-token'])),
        );
        self::assertSame([base64_encode('1:ab')], SecretMasker::secretValues(HttpRequest::get('https://h/', [], ['Authorization' => 'Basic ' . base64_encode('1:ab')])), 'пароль короче 4 символов не маскируется отдельно');
        self::assertSame([], SecretMasker::secretValues(HttpRequest::get('https://h/', [], ['Authorization' => 'Bearer x'])));
    }

    public function testSecretValuesInLists(): void
    {
        self::assertSame(['abcd', 'efgh'], SecretMasker::secretValues(HttpRequest::get('https://h/', ['token' => ['abcd', 'efgh'], 'merchant_id' => [1]])));
    }
}
