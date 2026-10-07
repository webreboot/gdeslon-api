<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Interface\Cli\OutputFailedException;

/**
 * @internal
 */
final class StdioChannel
{
    public const MAX_LINE = 1048576;

    public const TOO_LONG = "\0";

    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct(private $in, private $out)
    {
    }

    public function readLine(): ?string
    {
        while (true) {
            $line = fgets($this->in, self::MAX_LINE + 2);
            if ($line === false) {
                return null;
            }
            if (strlen($line) > self::MAX_LINE && !str_ends_with($line, "\n")) {
                $this->discardRestOfLine();

                return self::TOO_LONG;
            }
            $line = rtrim($line, "\r\n");
            if (strlen($line) > self::MAX_LINE) {
                return self::TOO_LONG;
            }
            if (trim($line) !== '') {
                return $line;
            }
        }
    }

    public function writeLine(string $message): void
    {
        $data = $message . "\n";
        $length = strlen($data);
        for ($written = 0; $written < $length; $written += $chunk) {
            $chunk = @fwrite($this->out, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                throw new OutputFailedException('Не удалось записать ответ в stdout (клиент закрыл поток?)');
            }
        }
        @fflush($this->out);
    }

    private function discardRestOfLine(): void
    {
        while (($chunk = fgets($this->in, self::MAX_LINE)) !== false) {
            if (str_ends_with($chunk, "\n")) {
                return;
            }
        }
    }
}
