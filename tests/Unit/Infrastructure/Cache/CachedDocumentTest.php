<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;

final class CachedDocumentTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $storedAt = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
        $document = new CachedDocument("{\"1\":\"Всё для \\\"шитья\\\"\"}\n", '"6724af75-26edb"', 'Fri, 01 Nov 2024 10:37:41 GMT', $storedAt);

        $serialized = $document->toString();
        $restored = CachedDocument::fromString($serialized);

        self::assertStringContainsString('"v":1', $serialized);
        self::assertNotNull($restored);
        self::assertSame($document->body(), $restored->body());
        self::assertSame('"6724af75-26edb"', $restored->etag());
        self::assertSame('Fri, 01 Nov 2024 10:37:41 GMT', $restored->lastModified());
        self::assertSame($storedAt->getTimestamp(), $restored->storedAt()->getTimestamp());
    }

    public function testWithoutValidators(): void
    {
        $document = new CachedDocument('{}', null, null, new \DateTimeImmutable('@0'));

        $restored = CachedDocument::fromString($document->toString());

        self::assertNotNull($restored);
        self::assertNull($restored->etag());
        self::assertNull($restored->lastModified());
    }

    public function testWithStoredAtChangesOnlyTime(): void
    {
        $document = new CachedDocument('{}', '"e"', 'lm', new \DateTimeImmutable('@0'));

        $touched = $document->withStoredAt(new \DateTimeImmutable('@100'));

        self::assertSame(100, $touched->storedAt()->getTimestamp());
        self::assertSame(0, $document->storedAt()->getTimestamp());
        self::assertSame(['{}', '"e"', 'lm'], [$touched->body(), $touched->etag(), $touched->lastModified()]);
    }

    #[DataProvider('corrupted')]
    public function testCorruptedEntryIsIgnored(string $value): void
    {
        self::assertNull(CachedDocument::fromString($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corrupted(): iterable
    {
        yield 'пусто' => [''];
        yield 'не JSON' => ['O:8:"stdClass":0:{}'];
        yield 'другая версия' => ['{"v":2,"etag":null,"lastModified":null,"storedAt":"2026-10-07T12:00:00+00:00","body":"{}"}'];
        yield 'нет тела' => ['{"v":1,"etag":null,"lastModified":null,"storedAt":"2026-10-07T12:00:00+00:00"}'];
        yield 'тело не строка' => ['{"v":1,"etag":null,"lastModified":null,"storedAt":"2026-10-07T12:00:00+00:00","body":5}'];
        yield 'дата не дата' => ['{"v":1,"etag":null,"lastModified":null,"storedAt":"вчера","body":"{}"}'];
        yield 'etag число' => ['{"v":1,"etag":5,"lastModified":null,"storedAt":"2026-10-07T12:00:00+00:00","body":"{}"}'];
        yield 'список' => ['[1,2]'];
    }
}
