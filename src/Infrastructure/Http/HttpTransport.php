<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\TransportException;

interface HttpTransport
{
    /**
     * @throws TransportException
     */
    public function send(HttpRequest $request): HttpResponse;
}
