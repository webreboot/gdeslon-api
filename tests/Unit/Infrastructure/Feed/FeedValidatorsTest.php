<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Feed;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedValidators;

final class FeedValidatorsTest extends TestCase
{
    public function testValuesAndArrayRoundTrip(): void
    {
        $validators = new FeedValidators('"6acf4fde-272ead2"', 'Wed, 14 Oct 2026 09:48:14 GMT');

        self::assertSame('"6acf4fde-272ead2"', $validators->etag());
        self::assertSame('Wed, 14 Oct 2026 09:48:14 GMT', $validators->lastModified());
        self::assertFalse($validators->isEmpty());
        self::assertSame(['etag' => '"6acf4fde-272ead2"', 'last_modified' => 'Wed, 14 Oct 2026 09:48:14 GMT'], $validators->toArray());
        self::assertEquals($validators, FeedValidators::fromArray($validators->toArray()));
    }

    public function testEmpty(): void
    {
        self::assertTrue((new FeedValidators(null, null))->isEmpty());
        self::assertTrue((new FeedValidators(' ', ''))->isEmpty());
        self::assertTrue(FeedValidators::fromArray([])->isEmpty());
        self::assertSame(['etag' => null, 'last_modified' => null], FeedValidators::fromArray(['etag' => null])->toArray());
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function garbage(): iterable
    {
        yield 'etag не строка' => [['etag' => 5]];
        yield 'last_modified массив' => [['last_modified' => []]];
        yield 'перевод строки в etag' => [['etag' => "\"a\"\r\nX-Injected: 1"]];
        yield 'перевод строки в дате' => [['last_modified' => "Wed\nX: y"]];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('garbage')]
    public function testGarbageRejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        FeedValidators::fromArray($data);
    }
}
