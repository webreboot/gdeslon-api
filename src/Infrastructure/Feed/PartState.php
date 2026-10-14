<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

/** @internal */
final class PartState
{
    public string $body = '';

    /** @var array<string, string> */
    public array $headers = [];

    public bool $truncated = false;

    public function __construct(private readonly int $limit)
    {
    }

    public function header(string $line): void
    {
        if (str_starts_with($line, 'HTTP/')) {
            $this->headers = [];

            return;
        }
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $this->headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['bytes' => strlen($this->body), 'truncated' => $this->truncated];
    }

    public function write(#[\SensitiveParameter] string $data): int
    {
        $room = $this->limit - strlen($this->body);
        if (strlen($data) > $room) {
            $this->body .= substr($data, 0, max(0, $room));
            $this->truncated = true;

            return 0;
        }
        $this->body .= $data;

        return strlen($data);
    }
}
