<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class FeedValidators
{
    private readonly ?string $etag;

    private readonly ?string $lastModified;

    public function __construct(?string $etag, ?string $lastModified)
    {
        $this->etag = self::header($etag, 'ETag');
        $this->lastModified = self::header($lastModified, 'Last-Modified');
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $etag = $data['etag'] ?? null;
        $lastModified = $data['last_modified'] ?? null;
        if (($etag !== null && !is_string($etag)) || ($lastModified !== null && !is_string($lastModified))) {
            throw new InvalidArgumentException('Валидаторы выгрузки: etag и last_modified должны быть строками или null');
        }

        return new self($etag, $lastModified);
    }

    public function etag(): ?string
    {
        return $this->etag;
    }

    public function lastModified(): ?string
    {
        return $this->lastModified;
    }

    public function isEmpty(): bool
    {
        return $this->etag === null && $this->lastModified === null;
    }

    /**
     * @return array{etag: ?string, last_modified: ?string}
     */
    public function toArray(): array
    {
        return ['etag' => $this->etag, 'last_modified' => $this->lastModified];
    }

    private static function header(?string $value, string $name): ?string
    {
        $value = trim($value ?? '');
        if (preg_match('~[\x00-\x1F\x7F]~', $value) === 1) {
            throw new InvalidArgumentException(sprintf('Валидаторы выгрузки: %s с управляющими символами', $name));
        }

        return $value === '' ? null : $value;
    }
}
