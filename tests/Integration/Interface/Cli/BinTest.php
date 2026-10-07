<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Cli;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\GdeSlon;

/**
 * bin/gdeslon отдельным процессом: autoload, argv, коды выхода и потоки. Без сети: только команды, которые не
 * доходят до запроса.
 */
final class BinTest extends TestCase
{
    public function testVersion(): void
    {
        [$code, $stdout, $stderr] = self::gdeslon(['--version']);

        self::assertSame(0, $code, $stderr);
        self::assertSame('gdeslon-api ' . GdeSlon::VERSION . "\n", $stdout);
        self::assertSame('', $stderr);
    }

    public function testUsageErrorGoesToStderr(): void
    {
        [$code, $stdout, $stderr] = self::gdeslon(['nosuch']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Неизвестная команда «nosuch»', $stderr);
    }

    public function testMissingTokenStopsBeforeNetwork(): void
    {
        [$code, $stdout, $stderr] = self::gdeslon(['search', 'платье']);

        self::assertSame(3, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('GDESLON_API_TOKEN', $stderr);
    }

    public function testOrdersWithoutKeysAndHelpInUtf8(): void
    {
        [$code, , $stderr] = self::gdeslon(['orders']);
        self::assertSame(3, $code);
        self::assertStringContainsString('GDESLON_API_KEY', $stderr);

        [$code, $stdout, $stderr] = self::gdeslon(['help']);
        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Команды:', $stdout);
        self::assertSame(1, preg_match('//u', $stdout));
    }

    public function testFullDiskOnStdoutIsAnErrorWithoutPhpNotice(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('нет /dev/full');
        }
        $command = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', dirname(__DIR__, 4) . '/bin/gdeslon', 'help'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/full', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        /** @var array{0: resource, 2: resource} $pipes */
        fclose($pipes[0]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process), $stderr);
        self::assertStringContainsString('stdout', $stderr);
        self::assertStringNotContainsString('Notice', $stderr);
        self::assertStringNotContainsString('.php', $stderr, 'без внутренних путей');
    }

    public function testComposerDeclaresExecutable(): void
    {
        $root = dirname(__DIR__, 4);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertSame(['bin/gdeslon'], $composer['bin'] ?? null);
        self::assertStringStartsWith("#!/usr/bin/env php\n<?php\n", (string) file_get_contents($root . '/bin/gdeslon'));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function gdeslon(array $arguments): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 4) . '/bin/gdeslon', ...$arguments];
        // окружение без ключей: GDESLON_* хоста в тест не попадают
        $env = ['PATH' => (string) getenv('PATH'), 'HOME' => sys_get_temp_dir()];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        /** @var array{resource, resource, resource} $pipes */
        [$stdin, $out, $err] = $pipes;
        fclose($stdin);
        $stdout = (string) stream_get_contents($out);
        $stderr = (string) stream_get_contents($err);
        fclose($out);
        fclose($err);

        return [proc_close($process), $stdout, $stderr];
    }
}
