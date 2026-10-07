<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Cache;

/**
 * @internal
 */
final class CachedDocument
{
    private const FORMAT_VERSION = 1;

    public function __construct(
        private readonly string $body,
        private readonly ?string $etag,
        private readonly ?string $lastModified,
        private readonly \DateTimeImmutable $storedAt,
    ) {
    }

    public static function fromString(string $value): ?self
    {
        try {
            $data = json_decode($value, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || ($data['v'] ?? null) !== self::FORMAT_VERSION) {
            return null;
        }

        $body = $data['body'] ?? null;
        $etag = $data['etag'] ?? null;
        $lastModified = $data['lastModified'] ?? null;
        $storedAt = $data['storedAt'] ?? null;
        if (!is_string($body) || !self::isOptionalString($etag) || !self::isOptionalString($lastModified) || !is_string($storedAt)) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $storedAt);
        if ($time === false) {
            return null;
        }

        return new self($body, $etag, $lastModified, $time);
    }

    public function toString(): string
    {
        return json_encode([
            'v' => self::FORMAT_VERSION,
            'etag' => $this->etag,
            'lastModified' => $this->lastModified,
            'storedAt' => $this->storedAt->format(\DateTimeInterface::ATOM),
            'body' => $this->body,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function withStoredAt(\DateTimeImmutable $storedAt): self
    {
        return new self($this->body, $this->etag, $this->lastModified, $storedAt);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function etag(): ?string
    {
        return $this->etag;
    }

    public function lastModified(): ?string
    {
        return $this->lastModified;
    }

    public function storedAt(): \DateTimeImmutable
    {
        return $this->storedAt;
    }

    /**
     * @phpstan-assert-if-true string|null $value
     */
    private static function isOptionalString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }
}
