<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Cli;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Cli\Console;
use Webreboot\GdeSlon\Interface\Cli\EnvFile;
use Webreboot\GdeSlon\Interface\Cli\Environment;
use Webreboot\GdeSlon\Interface\Cli\UsageException;

final class EnvironmentTest extends TestCase
{
    public function testEnvFile(): void
    {
        $contents = "\xEF\xBB\xBF# ключи\r\n"
            . "GDESLON_API_TOKEN=abc123\r\n"
            . "export GDESLON_USER_ID=1234\n"
            . "GDESLON_API_KEY=\"key with # space\"\n"
            . "GDESLON_CACHE_DIR='/tmp/x#y'\n"
            . "OTHER_SECRET=ignored\n"
            . "\n";

        self::assertSame([
            'GDESLON_API_TOKEN' => 'abc123',
            'GDESLON_USER_ID' => '1234',
            'GDESLON_API_KEY' => 'key with # space',
            'GDESLON_CACHE_DIR' => '/tmp/x#y',
        ], EnvFile::parse($contents));
    }

    public function testEnvFileErrors(): void
    {
        try {
            EnvFile::parse("GDESLON_API_TOKEN=ok\nnot-a-pair-secret-value\n");
            self::fail('Ожидалось исключение');
        } catch (UsageException $e) {
            self::assertStringContainsString('строка 2', $e->getMessage());
            self::assertStringNotContainsString('secret-value', $e->getMessage());
        }

        $this->expectException(UsageException::class);
        EnvFile::load('/nonexistent/gdeslon.env');
    }

    public function testEnvironment(): void
    {
        $env = new Environment(['GDESLON_API_TOKEN' => '  ', 'GDESLON_USER_ID' => '1234'], ['GDESLON_API_TOKEN' => 'from-file', 'GDESLON_API_KEY' => 'file-key', 'GDESLON_USER_ID' => '999']);

        self::assertSame('from-file', $env->token(), 'пустая переменная окружения не перекрывает файл');
        self::assertSame('1234', $env->userId(), 'окружение важнее файла');
        self::assertSame('file-key', $env->apiKey());
        self::assertSame(['from-file', 'file-key'], $env->secrets());
        self::assertNull((new Environment([]))->token());
    }

    public function testCacheDirectory(): void
    {
        self::assertSame('/c', (new Environment(['GDESLON_CACHE_DIR' => '/c', 'XDG_CACHE_HOME' => '/x', 'HOME' => '/h']))->cacheDirectory());
        self::assertSame('/x/gdeslon-api', (new Environment(['XDG_CACHE_HOME' => '/x', 'HOME' => '/h']))->cacheDirectory());
        self::assertSame('/h/.cache/gdeslon-api', (new Environment(['HOME' => '/h']))->cacheDirectory());
        self::assertSame('C:\\Users\\u\\AppData\\Local\\gdeslon-api\\cache', (new Environment(['LOCALAPPDATA' => 'C:\\Users\\u\\AppData\\Local']))->cacheDirectory());
        self::assertNull((new Environment([]))->cacheDirectory());
    }

    public function testConsoleMasksSecrets(): void
    {
        [$console, $out, $err] = self::console(['secret-token', 'abc']);

        $console->out("token secret-token\n");
        $console->err("err secret-token abc\n");
        $console->revealStdout();
        $console->out("reveal secret-token\n");
        $console->err("still secret-token\n");

        self::assertSame("token ***\nreveal secret-token\n", self::read($out));
        self::assertSame("err *** abc\nstill ***\n", self::read($err), 'секрет короче 4 символов не маскируется');
    }

    public function testConsoleStripsTerminalControl(): void
    {
        [$console, $out, $err] = self::console([]);

        $console->out("Путь: Платья\e]0;pwned\x07\e[2J\r\n");
        $console->err("order_date: x\e[31m\xC2\x9By\tz\n");

        self::assertSame("Путь: Платья ]0;pwned  [2J \n", self::read($out), 'управляющие символы — пробелом, перевод строки остаётся');
        self::assertSame("order_date: x [31m y z\n", self::read($err));
    }

    public function testConsoleWriteFailure(): void
    {
        $readOnly = fopen('php://memory', 'rb');
        self::assertIsResource($readOnly);
        $console = new Console(self::stream(''), $readOnly, self::stream(''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stdout');
        $console->out("данные\n");
    }

    public function testConsoleReadsLine(): void
    {
        $in = self::stream("yes\nnext\n");
        $console = new Console($in, self::stream(''), self::stream(''));

        self::assertSame('yes', $console->readLine());
        self::assertSame('next', $console->readLine());
        self::assertNull($console->readLine());
    }

    /**
     * @param list<string> $secrets
     *
     * @return array{Console, resource, resource}
     */
    private static function console(array $secrets): array
    {
        $out = self::stream('');
        $err = self::stream('');

        return [new Console(self::stream(''), $out, $err, $secrets), $out, $err];
    }

    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private static function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
