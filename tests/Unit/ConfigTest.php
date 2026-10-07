<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new Config();

        self::assertSame(30.0, $config->timeout());
        self::assertSame(10.0, $config->connectTimeout());
        self::assertNull($config->userAgent());
        self::assertSame(16384, $config->rangeChunkSize());
        self::assertSame(86400, $config->cacheTtl());
    }

    public function testRangeCanBeDisabledAndCacheAlwaysRevalidated(): void
    {
        $config = new Config(rangeChunkSize: null, cacheTtl: 0);

        self::assertNull($config->rangeChunkSize());
        self::assertSame(0, $config->cacheTtl());
    }

    #[DataProvider('invalidCacheAndRange')]
    public function testRejectsInvalidRangeAndCacheSettings(?int $rangeChunkSize, int $cacheTtl): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(rangeChunkSize: $rangeChunkSize, cacheTtl: $cacheTtl);
    }

    /**
     * @return iterable<string, array{?int, int}>
     */
    public static function invalidCacheAndRange(): iterable
    {
        yield 'часть 0' => [0, 86400];
        yield 'часть меньше 1 КиБ' => [1023, 86400];
        yield 'часть отрицательная' => [-1, 86400];
        yield 'TTL отрицательный' => [16384, -1];
    }

    public function testCustomValues(): void
    {
        $config = new Config(timeout: 60.0, connectTimeout: 5.0, userAgent: 'my-app/1.0');

        self::assertSame(60.0, $config->timeout());
        self::assertSame(5.0, $config->connectTimeout());
        self::assertSame('my-app/1.0', $config->userAgent());
    }

    #[DataProvider('invalidTimeouts')]
    public function testRejectsNonPositiveTimeouts(float $timeout, float $connectTimeout): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(timeout: $timeout, connectTimeout: $connectTimeout);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'timeout 0' => [0.0, 10.0];
        yield 'timeout отрицательный' => [-1.0, 10.0];
        yield 'connectTimeout 0' => [30.0, 0.0];
    }

    public function testApiToken(): void
    {
        self::assertNull((new Config())->apiToken());
        self::assertSame('abc123', (new Config(apiToken: 'abc123'))->apiToken());
    }

    #[DataProvider('blankTokens')]
    public function testBlankApiTokenIsRejected(string $token): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('XML API пуст');

        new Config(apiToken: $token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTokens(): iterable
    {
        yield 'пустой' => [''];
        yield 'пробелы' => ['   '];
    }

    public function testApiTokenIsHiddenFromDumps(): void
    {
        $config = new Config(apiToken: 'secret-token');

        ob_start();
        var_dump($config);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('secret-token', print_r($config, true));
        self::assertStringNotContainsString('secret-token', $dump);
        self::assertStringContainsString('***', $dump);
    }

    public function testMerchantCacheTtl(): void
    {
        self::assertSame(3600, (new Config())->merchantCacheTtl());
        self::assertSame(0, (new Config(merchantCacheTtl: 0))->merchantCacheTtl());

        $this->expectException(InvalidArgumentException::class);
        new Config(merchantCacheTtl: -1);
    }

    public function testConfigWithTokenIsNotSerializable(): void
    {
        self::assertInstanceOf(Config::class, unserialize(serialize(new Config(timeout: 60.0))));

        $restored = unserialize(serialize(new Config(timeout: 60.0, merchantCacheTtl: 0)));
        self::assertInstanceOf(Config::class, $restored);
        self::assertSame(60.0, $restored->timeout());
        self::assertSame(0, $restored->merchantCacheTtl());

        try {
            serialize(new Config(apiToken: 'secret-token'));
            self::fail('Ожидалось исключение');
        } catch (GdeSlonException $e) {
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testSalesApiCredentials(): void
    {
        $config = new Config();
        self::assertNull($config->userId());
        self::assertNull($config->apiKey());

        $config = new Config(userId: 1234, apiKey: ' test-api-key ');
        self::assertSame('1234', $config->userId());
        self::assertSame('test-api-key', $config->apiKey());
        self::assertSame('1234', (new Config(userId: ' 1234 ', apiKey: 'test-api-key'))->userId());
    }

    /**
     * @param int|string|null $userId
     */
    #[DataProvider('invalidSalesCredentials')]
    public function testInvalidSalesCredentials(int|string|null $userId, ?string $apiKey, string $message): void
    {
        try {
            new Config(userId: $userId, apiKey: $apiKey);
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            if ($apiKey !== null && trim($apiKey) !== '') {
                self::assertStringNotContainsString(trim($apiKey), $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{int|string|null, string|null, string}>
     */
    public static function invalidSalesCredentials(): iterable
    {
        yield 'ID буквами' => ['abc', 'test-api-key', 'ID пользователя'];
        yield 'ID ноль' => ['0', 'test-api-key', 'ID пользователя'];
        yield 'ID int ноль' => [0, 'test-api-key', 'ID пользователя'];
        yield 'пустой ID' => ['', 'test-api-key', 'ID пользователя'];
        yield 'пустой ключ' => ['1234', '', 'Ключ API по продажам'];
        yield 'ключ из пробелов' => ['1234', '  ', 'Ключ API по продажам'];
        yield 'ключ с двоеточием' => ['1234', 'test:api-key', 'двоеточ'];
        yield 'только ID' => ['1234', null, 'вместе'];
        yield 'только ключ' => [null, 'test-api-key', 'вместе'];
    }

    public function testSalesCredentialsAreHiddenFromDumpsAndSerialization(): void
    {
        $config = new Config(userId: 987654, apiKey: 'test-api-key');

        ob_start();
        var_dump($config);
        $dump = (string) ob_get_clean() . print_r($config, true);
        self::assertStringNotContainsString('test-api-key', $dump);
        self::assertStringNotContainsString('987654', $dump);

        try {
            serialize($config);
            self::fail('Ожидалось исключение');
        } catch (GdeSlonException $e) {
            self::assertStringNotContainsString('test-api-key', $e->getMessage());
        }
    }
}
