<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api;

use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;

/**
 * @template T
 *
 * @internal
 */
final class CacheHit
{
    /**
     * @param T $value
     */
    public function __construct(
        public readonly CachedDocument $document,
        public readonly mixed $value,
    ) {
    }
}
