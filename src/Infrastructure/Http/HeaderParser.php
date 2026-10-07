<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

/** @internal */
final class HeaderParser
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    public function addLine(string $line): void
    {
        $line = trim($line);
        if (str_starts_with($line, 'HTTP/')) {
            $this->headers = [];

            return;
        }

        $colon = strpos($line, ':');
        if ($colon === false || $colon === 0) {
            return;
        }

        $name = strtolower(trim(substr($line, 0, $colon)));
        $this->headers[$name][] = trim(substr($line, $colon + 1));
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
