<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Postback;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Interface\Postback\HeaderSecret;
use Webreboot\GdeSlon\Interface\Postback\PostbackAuthenticationException;
use Webreboot\GdeSlon\Interface\Postback\PostbackRequest;

final class HeaderSecretTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789';

    public function testValidHeader(): void
    {
        $secret = new HeaderSecret('X-Gdeslon-Secret', self::SECRET);

        $secret->verify(new PostbackRequest('GET', ['x-gdeslon-secret' => ' ' . self::SECRET . ' ']));
        $secret->verify(new PostbackRequest('POST', ['X-GDESLON-SECRET' => [self::SECRET]]));

        self::assertSame('X-Gdeslon-Secret', $secret->header());
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    #[DataProvider('rejected')]
    public function testRejected(array $headers, string $reason): void
    {
        try {
            (new HeaderSecret('X-Gdeslon-Secret', self::SECRET))->verify(new PostbackRequest('GET', $headers));
            self::fail('Ожидалось исключение');
        } catch (PostbackAuthenticationException $e) {
            self::assertSame(401, $e->responseStatus());
            self::assertStringNotContainsString('X-Gdeslon-Secret', $e->getMessage(), 'ответ 401 видит кто угодно — имя заголовка не подсказываем');
            self::assertStringContainsString($reason, $e->getMessage());
            foreach ([self::SECRET, 'wrong-secret-0123456789', 'test-secret'] as $value) {
                self::assertStringNotContainsString($value, $e->getMessage());
                self::assertStringNotContainsString($value, $e->getTraceAsString());
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>, string}>
     */
    public static function rejected(): iterable
    {
        yield 'неверный' => [['X-Gdeslon-Secret' => 'wrong-secret-0123456789'], 'неверн'];
        yield 'другой длины' => [['X-Gdeslon-Secret' => 'test-secret'], 'неверн'];
        yield 'пустой' => [['X-Gdeslon-Secret' => ''], 'неверн'];
        yield 'отсутствует' => [['Authorization' => self::SECRET], 'нет'];
        yield 'два значения' => [['X-Gdeslon-Secret' => [self::SECRET, self::SECRET]], 'раз'];
    }

    public function testInvalidConfiguration(): void
    {
        foreach ([['', self::SECRET], [' ', self::SECRET], ['X-Secret', ''], ['X-Secret', 'short-secret'], ['X Secret', self::SECRET]] as [$header, $value]) {
            try {
                new HeaderSecret($header, $value);
                self::fail('Ожидалось исключение: ' . $header);
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            }
        }
    }

    public function testValueIsHiddenFromDumps(): void
    {
        $secret = new HeaderSecret('X-Gdeslon-Secret', self::SECRET);

        ob_start();
        var_dump($secret);
        $dump = (string) ob_get_clean() . print_r($secret, true);

        self::assertStringNotContainsString(self::SECRET, $dump);
        self::assertStringContainsString('X-Gdeslon-Secret', $dump);
    }

    public function testSwappedArgumentsDoNotLeakSecret(): void
    {
        try {
            new HeaderSecret('abc/def+ghi=jkl0123', 'X-Gdeslon-Secret');
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('abc/def', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new HeaderSecret('X-Gdeslon-Secret-Header', 'x-gdeslon-secret-header');
    }
}
