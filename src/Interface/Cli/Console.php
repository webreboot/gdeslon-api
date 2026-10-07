<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Infrastructure\Http\SecretMasker;

/**
 * @internal
 */
final class Console
{
    private const MIN_SECRET_LENGTH = 4;

    /** @var list<string> */
    private readonly array $secrets;

    private bool $revealStdout = false;

    /**
     * @param resource     $in
     * @param resource     $out
     * @param resource     $err
     * @param list<string> $secrets
     */
    public function __construct(
        private $in,
        private $out,
        private $err,
        #[\SensitiveParameter]
        array $secrets = [],
    ) {
        $this->secrets = array_values(array_filter($secrets, static fn (string $secret): bool => strlen($secret) >= self::MIN_SECRET_LENGTH));
    }

    /**
     * @param list<string> $secrets
     */
    public function withSecrets(#[\SensitiveParameter] array $secrets): self
    {
        return new self($this->in, $this->out, $this->err, $secrets);
    }

    public function out(string $text): void
    {
        self::write($this->out, 'stdout', $this->revealStdout ? $text : SecretMasker::maskValues($text, $this->secrets));
    }

    public function err(string $text): void
    {
        self::write($this->err, 'stderr', SecretMasker::maskValues($text, $this->secrets));
    }

    /**
     * @param resource $stream
     */
    private static function write($stream, string $name, string $text): void
    {
        $text = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', ' ', $text);
        $length = strlen($text);
        for ($written = 0; $written < $length; $written += $chunk) {
            $chunk = @fwrite($stream, substr($text, $written));
            if ($chunk === false || $chunk === 0) {
                throw new OutputFailedException(sprintf('Не удалось записать в %s (полный диск или закрытый поток?)', $name));
            }
        }
    }

    public function mask(string $text): string
    {
        return SecretMasker::maskValues($text, $this->secrets);
    }

    /**
     * @return array{resource, resource}
     */
    public function protocolStreams(): array
    {
        return [$this->in, $this->out];
    }

    public function revealStdout(): void
    {
        $this->revealStdout = true;
    }

    public function readLine(): ?string
    {
        $line = fgets($this->in);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['secrets' => count($this->secrets), 'revealStdout' => $this->revealStdout];
    }
}
