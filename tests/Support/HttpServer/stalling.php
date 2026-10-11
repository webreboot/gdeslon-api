<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1) . "\n");
fclose(STDOUT);

$clients = [];
while (($client = @stream_socket_accept($server, 30)) !== false) {
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($client, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    if ($mode === 'connect-established' && str_starts_with($request, 'CONNECT ')) {
        fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");
    }
    if ($mode === 'partial-body' && str_starts_with($request, 'GET ')) {
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/xml\r\nContent-Length: 100000\r\n\r\n" . str_repeat('x', 16011));
    }
    $clients[] = $client;
}
