<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Interface\Cli\OutputFailedException;

/**
 * Цикл stdio-сервера: строка stdin → ответ в stdout. EOF stdin — штатное завершение (0); клиент закрыл stdout — 1.
 *
 * @internal
 */
final class Server
{
    public function __construct(private readonly StdioChannel $channel, private readonly Protocol $protocol, private readonly ToolContext $context)
    {
    }

    public function run(): int
    {
        while (($line = $this->channel->readLine()) !== null) {
            $response = $this->protocol->handle($line);
            if ($response === null) {
                continue;
            }
            try {
                $this->channel->writeLine($response);
            } catch (OutputFailedException $error) {
                $this->context->log($error->getMessage());

                return 1;
            }
        }

        return 0;
    }
}
