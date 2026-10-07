<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Cli;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Catalog\CategoryRepository;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;
use Webreboot\GdeSlon\Interface\Cli\Application;
use Webreboot\GdeSlon\Interface\Cli\Console;
use Webreboot\GdeSlon\Tests\Support\CliTester;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;

final class ApplicationTest extends TestCase
{
    public function testVersion(): void
    {
        foreach ([['--version'], ['-V']] as $argv) {
            $cli = new CliTester();
            self::assertSame(0, $cli->run($argv));
            self::assertSame('gdeslon-api ' . GdeSlon::VERSION . "\n", $cli->stdout);
            self::assertSame(0, $cli->factoryCalls, 'без SDK и сети');
        }
    }

    public function testHelp(): void
    {
        foreach ([[], ['help'], ['--help']] as $argv) {
            $cli = new CliTester();
            self::assertSame(0, $cli->run($argv));
            foreach (['categories', 'merchants show', 'search', 'orders', 'lost-orders submit', 'coupons kinds'] as $command) {
                self::assertStringContainsString($command, $cli->stdout);
            }
            self::assertStringContainsString('GDESLON_API_TOKEN', $cli->stdout);
        }

        foreach ([['help', 'search'], ['search', '--help'], ['search', '-h']] as $argv) {
            $cli = new CliTester();
            self::assertSame(0, $cli->run($argv));
            self::assertStringContainsString('--limit=', $cli->stdout);
            self::assertStringContainsString('--sort=', $cli->stdout);
            self::assertSame(0, $cli->factoryCalls);
        }

        $cli = new CliTester();
        self::assertSame(0, $cli->run(['help', 'lost-orders', 'submit']));
        self::assertStringContainsString('РЕАЛЬН', $cli->stdout);

        self::assertSame(2, (new CliTester())->run(['help', 'nosuch']));
    }

    public function testUsageErrors(): void
    {
        foreach ([['nosuch'], ['merchants', 'nosuch'], ['categories', '1', '2'], ['--format=csv', 'categories'], ['categories', '--nosuch'], ['--timeout=0', 'categories'],
            ['--timeout=abc', 'categories'], ['--no-cache', '--cache-dir=/tmp', 'categories'], ['--cache-dir', '--no-cache', 'categories']] as $argv) {
            $cli = new CliTester();
            self::assertSame(2, $cli->run($argv), implode(' ', $argv));
            self::assertSame('', $cli->stdout);
            self::assertStringContainsString('gdeslon help', $cli->stderr);
            self::assertSame(0, $cli->factoryCalls, 'без запроса');
        }
    }

    public function testConfigFromOptionsAndEnvironment(): void
    {
        $cli = self::withCategories();
        self::assertSame(0, $cli->run(['--timeout=60', 'categories', '--no-cache'], ['GDESLON_API_TOKEN' => 'env-token']));
        self::assertSame(60.0, self::config($cli)->timeout());
        self::assertSame('env-token', self::config($cli)->apiToken());
        self::assertNull($cli->cache);

        $dir = sys_get_temp_dir() . '/gdeslon-cli-' . bin2hex(random_bytes(4));
        $cli = self::withCategories();
        self::assertSame(0, $cli->run(['categories', '--cache-dir=' . $dir]));
        self::assertInstanceOf(FileCacheStore::class, $cli->cache);

        $cli = self::withCategories();
        self::assertSame(0, $cli->run(['categories'], ['HOME' => $dir]));
        self::assertInstanceOf(FileCacheStore::class, $cli->cache, 'кэш по умолчанию в каталоге пользователя');

        $cli = self::withCategories();
        self::assertSame(0, $cli->run(['categories'], ['GDESLON_USER_ID' => '1234']), 'неполная пара ключей не мешает другим командам');
        self::assertNull(self::config($cli)->userId());
    }

    public function testCommandRoutingSkipsOptionValues(): void
    {
        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops-public.xml')));

        self::assertSame(0, $cli->run(['merchants', '--search', 'show', '--no-cache']), $cli->stderr);
        self::assertStringContainsString('Ничего не найдено.', $cli->stderr, '«show» — значение --search, а не подкоманда');
    }

    public function testStdoutWriteFailureIsAnError(): void
    {
        $out = fopen('php://memory', 'rb');
        $err = fopen('php://memory', 'r+b');
        $in = fopen('php://memory', 'rb');
        self::assertIsResource($out);
        self::assertIsResource($err);
        self::assertIsResource($in);
        $application = new Application(static fn () => throw new \LogicException('без SDK'), new Console($in, $out, $err), [], FrozenClock::at('2026-10-07T10:00:00Z'));

        self::assertSame(1, $application->run(['help']));
        rewind($err);
        self::assertStringContainsString('stdout', (string) stream_get_contents($err));
    }

    public function testEnvFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gdeslon-env');
        self::assertIsString($file);
        file_put_contents($file, "GDESLON_API_TOKEN=file-token\nGDESLON_USER_ID=1234\nGDESLON_API_KEY=file-key\n");
        try {
            $cli = self::withCategories();
            self::assertSame(0, $cli->run(['categories', '--env-file=' . $file, '--no-cache'], ['GDESLON_API_TOKEN' => 'env-token']));
            self::assertSame('env-token', self::config($cli)->apiToken(), 'окружение важнее файла');
            self::assertSame('1234', self::config($cli)->userId());
            self::assertSame('file-key', self::config($cli)->apiKey());
        } finally {
            unlink($file);
        }

        $cli = new CliTester();
        self::assertSame(2, $cli->run(['categories', '--env-file=/nonexistent/env']));
    }

    public function testInternalErrorAndSecretMasking(): void
    {
        $failing = new class () implements CategoryRepository {
            public function all(): CategoryTree
            {
                throw new \RuntimeException('boom secret-token-0123');
            }
        };
        $cli = new CliTester(build: static fn () => new GdeSlon(new FakeHttpTransport(), $failing));

        self::assertSame(1, $cli->run(['categories', '--no-cache'], ['GDESLON_API_TOKEN' => 'secret-token-0123']));
        self::assertSame('', $cli->stdout);
        self::assertStringContainsString('Внутренняя ошибка (RuntimeException): boom ***', $cli->stderr);
        self::assertStringNotContainsString('secret-token-0123', $cli->stderr);
    }

    private static function config(CliTester $cli): Config
    {
        self::assertNotNull($cli->config, 'SDK не создавался');

        return $cli->config;
    }

    private static function withCategories(): CliTester
    {
        return new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('categories/categories.json')));
    }
}
