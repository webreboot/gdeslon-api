<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\CacheStore;
use Webreboot\GdeSlon\Interface\Cli\Application;
use Webreboot\GdeSlon\Interface\Cli\Console;

/**
 * Запуск CLI в тесте: потоки в памяти, фейковый транспорт, замороженные часы. Запоминает Config и кэш, которые
 * Application передал фабрике SDK.
 */
final class CliTester
{
    public ?Config $config = null;

    public ?CacheStore $cache = null;

    public int $factoryCalls = 0;

    public string $stdout = '';

    public string $stderr = '';

    /**
     * @param (\Closure(Config, ?CacheStore): GdeSlon)|null $build свой фасад (фейки портов); по умолчанию — GdeSlon::create
     */
    public function __construct(
        public readonly FakeHttpTransport $transport = new FakeHttpTransport(),
        private readonly ?Clock $clock = null,
        private readonly ?\Closure $build = null,
    ) {
    }

    /**
     * @param list<string>          $argv аргументы без имени программы
     * @param array<string, string> $env
     */
    public function run(array $argv, array $env = [], string $stdin = ''): int
    {
        $clock = $this->clock ?? FrozenClock::at('2026-10-07T10:00:00Z');
        $factory = function (Config $config, ?CacheStore $cache) use ($clock): GdeSlon {
            $this->config = $config;
            $this->cache = $cache;
            $this->factoryCalls++;

            return $this->build === null ? GdeSlon::create($config, $this->transport, $cache, $clock) : ($this->build)($config, $cache);
        };

        $in = self::stream($stdin);
        $out = self::stream('');
        $err = self::stream('');
        $code = (new Application($factory, new Console($in, $out, $err), $env, $clock))->run($argv);

        $this->stdout = self::read($out);
        $this->stderr = self::read($err);

        return $code;
    }

    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('php://memory');
        }
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
