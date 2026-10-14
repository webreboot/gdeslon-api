<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

/** @internal */
final class PartResponse
{
    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        array $headers,
        #[\SensitiveParameter]
        private readonly string $body,
        private readonly bool $truncated = false,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'bytes' => strlen($this->body), 'truncated' => $this->truncated];
    }
}
