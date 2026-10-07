<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Mcp;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Cli\OutputFailedException;
use Webreboot\GdeSlon\Interface\Mcp\StdioChannel;
use Webreboot\GdeSlon\Tests\Support\FailingStream;

final class StdioChannelTest extends TestCase
{
    public function testReadsLines(): void
    {
        $channel = new StdioChannel(self::stream("{\"a\":1}\r\n\n  \n{\"b\":2}"), self::stream(''));

        self::assertSame('{"a":1}', $channel->readLine());
        self::assertSame('{"b":2}', $channel->readLine(), 'пустые строки пропускаются, последняя — без \n');
        self::assertNull($channel->readLine());
    }

    public function testTooLongLineIsDiscarded(): void
    {
        $long = str_repeat('x', StdioChannel::MAX_LINE + 10);
        $channel = new StdioChannel(self::stream($long . "\n{\"ok\":1}\n"), self::stream(''));

        self::assertSame(StdioChannel::TOO_LONG, $channel->readLine());
        self::assertSame('{"ok":1}', $channel->readLine(), 'следующая строка читается целиком');
        self::assertNull($channel->readLine());
    }

    public function testLineOfExactlyMaxLength(): void
    {
        $line = str_repeat('y', StdioChannel::MAX_LINE);
        $channel = new StdioChannel(self::stream($line . "\n"), self::stream(''));

        self::assertSame($line, $channel->readLine());
    }

    public function testWritesLines(): void
    {
        $out = self::stream('');
        $channel = new StdioChannel(self::stream(''), $out);

        $channel->writeLine('{"a":1}');
        $channel->writeLine('{"b":2}');

        rewind($out);
        self::assertSame("{\"a\":1}\n{\"b\":2}\n", stream_get_contents($out));
    }

    public function testWriteFailure(): void
    {
        $channel = new StdioChannel(self::stream(''), FailingStream::open());
        FailingStream::$failing = true;

        try {
            $this->expectException(OutputFailedException::class);
            $channel->writeLine('{}');
        } finally {
            FailingStream::$failing = false;
        }
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
}
