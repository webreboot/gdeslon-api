<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Shared\DataOrigin;

final class DataOriginTest extends TestCase
{
    public function testIsCurrent(): void
    {
        self::assertTrue(DataOrigin::Fetched->isCurrent());
        self::assertTrue(DataOrigin::Cached->isCurrent());
        self::assertTrue(DataOrigin::Revalidated->isCurrent());
        self::assertFalse(DataOrigin::Stale->isCurrent());
    }

    public function testValuesAreStableSnakeCase(): void
    {
        self::assertSame(
            ['fetched', 'cached', 'revalidated', 'stale'],
            array_map(static fn (DataOrigin $origin): string => $origin->value, DataOrigin::cases()),
        );
    }
}
