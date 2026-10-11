<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api;

use Webreboot\GdeSlon\Domain\Shared\DataOrigin;

/**
 * @template T
 *
 * @internal
 */
final class LoadedDocument
{
    /**
     * @param T $value
     */
    public function __construct(
        public readonly mixed $value,
        public readonly DataOrigin $origin,
    ) {
    }
}
