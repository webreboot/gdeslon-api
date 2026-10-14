<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Cli;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Tests\Support\CliTester;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\ZipBuilder;

final class FeedCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gdeslon-feed-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testStatsTable(): void
    {
        $cli = new CliTester();

        self::assertSame(0, $cli->run(['feed', 'stats', $this->xml()]));

        self::assertStringContainsString('2026-10-13 21:50', $cli->stdout);
        self::assertMatchesRegularExpression('/Офферов:\s+21/u', $cli->stdout);
        self::assertMatchesRegularExpression('/Магазинов:\s+13/u', $cli->stdout);
        self::assertMatchesRegularExpression('/Категорий:\s+27/u', $cli->stdout);
        self::assertMatchesRegularExpression('/С цветом:\s+18 \(86%\)/u', $cli->stdout);
        self::assertMatchesRegularExpression('/С размером:\s+13 \(62%\)/u', $cli->stdout);
        self::assertMatchesRegularExpression('/С полом:\s+10 \(48%\)/u', $cli->stdout);
        self::assertMatchesRegularExpression('/С erid:\s+18 \(86%\)/u', $cli->stdout);
        self::assertMatchesRegularExpression('/86276\s+4\n/u', $cli->stdout);
        self::assertMatchesRegularExpression('/Цвет\s+18\s+86%/u', $cli->stdout);
        self::assertStringContainsString('Пропущено записей: 1', $cli->stderr);
        self::assertStringContainsString('цена 0, оффер пропущен', $cli->stderr);
        self::assertSame([], $cli->transport->requests());
    }

    public function testStatsJson(): void
    {
        $cli = new CliTester();

        self::assertSame(0, $cli->run(['feed', 'stats', $this->xml(), '--format=json']));

        $data = self::stats($cli->stdout);
        self::assertSame('2026-10-13T21:50:00+03:00', $data['generated_at']);
        self::assertSame(21, $data['offers']);
        self::assertSame(1, $data['skipped']);
        self::assertSame(27, $data['categories']);
        self::assertSame(13, $data['merchants']['count']);
        self::assertSame(['merchant_id' => 86276, 'offers' => 4], $data['merchants']['top'][0]);
        self::assertSame(['name' => 'Цвет', 'offers' => 18, 'share' => 0.86], $data['params'][0]);
        self::assertSame(['color', 'size', 'gender', 'erid'], array_keys($data['coverage']));
        self::assertSame(['offers' => 18, 'share' => 0.86], $data['coverage']['color']);
    }

    public function testNumericParamNamesAreCounted(): void
    {
        $xml = str_replace('<param name="&#x426;&#x432;&#x435;&#x442;">', '<param name="2026">', Fixtures::read('feeds/feed.xml'));
        file_put_contents($this->dir . '/feed.xml', $xml);
        $cli = new CliTester();

        self::assertSame(0, $cli->run(['feed', 'stats', $this->dir . '/feed.xml', '--format=json']));

        $data = self::stats($cli->stdout);
        self::assertSame(['name' => '2026', 'offers' => 18, 'share' => 0.86], $data['params'][0]);
    }

    public function testStatsOfZipUnpacksToTemporaryFile(): void
    {
        $zip = (new ZipBuilder())->add('secret0123456789.xml', Fixtures::read('feeds/feed.xml'))->writeTo($this->dir . '/feed.zip');
        $before = glob(sys_get_temp_dir() . '/gdeslon-feed-stats*') ?: [];
        $cli = new CliTester();

        self::assertSame(0, $cli->run(['feed', 'stats', $zip, '--format=json']));

        $data = json_decode($cli->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(21, $data['offers']);
        self::assertSame($before, glob(sys_get_temp_dir() . '/gdeslon-feed-stats*') ?: []);
        self::assertStringNotContainsString('secret0123456789', $cli->stdout . $cli->stderr);
    }

    public function testStatsUsageAndErrors(): void
    {
        $cli = new CliTester();
        self::assertSame(2, $cli->run(['feed', 'stats']));
        self::assertStringContainsString('путь к файлу выгрузки', $cli->stderr);

        $cli = new CliTester();
        self::assertSame(2, $cli->run(['feed', 'stats', $this->dir . '/missing.xml']));
        self::assertStringContainsString('ожидался путь к существующему локальному файлу', $cli->stderr);

        file_put_contents($this->dir . '/login.html', '<!DOCTYPE html><html><body>Пароль</body></html>');
        $cli = new CliTester();
        self::assertSame(1, $cli->run(['feed', 'stats', $this->dir . '/login.html']));
        self::assertStringStartsWith('Ошибка: Выгрузка', $cli->stderr);
        self::assertSame('', $cli->stdout);
    }

    /**
     * @return array{generated_at: ?string, offers: int, skipped: int, categories: int, merchants: array{count: int, top: list<array{merchant_id: int, offers: int}>}, params: list<array{name: string, offers: int, share: float}>, coverage: array<string, array{offers: int, share: float}>}
     */
    private static function stats(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['generated_at', 'offers', 'skipped', 'categories', 'merchants', 'params', 'coverage'], array_keys($data));

        /** @var array{generated_at: ?string, offers: int, skipped: int, categories: int, merchants: array{count: int, top: list<array{merchant_id: int, offers: int}>}, params: list<array{name: string, offers: int, share: float}>, coverage: array<string, array{offers: int, share: float}>} $data */
        return $data;
    }

    private function xml(): string
    {
        $path = $this->dir . '/feed.xml';
        file_put_contents($path, Fixtures::read('feeds/feed.xml'));

        return $path;
    }
}
