<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Cache;

use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;

/**
 * @internal
 */
final class DocumentCache
{
    public function __construct(
        private readonly CacheStore $store,
        private readonly Clock $clock,
        private readonly int $ttl,
    ) {
        if ($ttl < 0) {
            throw new InvalidArgumentException(sprintf('Время жизни кэша не может быть отрицательным, получено %d', $ttl));
        }
    }

    public static function key(string $url): string
    {
        return 'gdeslon_' . sha1($url);
    }

    /**
     * @phpstan-impure
     */
    public function find(string $url): ?CachedDocument
    {
        $value = $this->store->get(self::key($url));

        return $value === null ? null : CachedDocument::fromString($value);
    }

    public function isFresh(CachedDocument $document): bool
    {
        $age = $this->clock->now()->getTimestamp() - $document->storedAt()->getTimestamp();

        return $age >= 0 && $age < $this->ttl;
    }

    public function save(string $url, HttpResponse $response): bool
    {
        return $this->write($url, new CachedDocument(
            $response->body(),
            $response->header('etag'),
            $response->header('last-modified'),
            $this->clock->now(),
        ));
    }

    public function touch(string $url, CachedDocument $document): void
    {
        $this->write($url, $document->withStoredAt($this->clock->now()));
    }

    private function write(string $url, CachedDocument $document): bool
    {
        try {
            return $this->store->set(self::key($url), $document->toString());
        } catch (\JsonException) {
            return false;
        }
    }
}
