<?php

declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = is_string($uri) ? $uri : '/';
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

if ($path === '/json') {
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true,"name":"Всё для шитья"}';

    return true;
}

if ($path === '/echo') {
    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'query' => $_GET,
        'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
        'body' => file_get_contents('php://input'),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return true;
}

if ($path === '/postback') {
    require dirname(__DIR__, 3) . '/vendor/autoload.php';
    $receiver = new \Webreboot\GdeSlon\Interface\Postback\PostbackReceiver(
        new \Webreboot\GdeSlon\Interface\Postback\HeaderSecret('X-Gdeslon-Secret', 'test-secret-0123456789'),
    );
    header('Content-Type: application/json; charset=utf-8');
    try {
        $postback = $receiver->receive(\Webreboot\GdeSlon\Interface\Postback\PostbackRequest::fromGlobals());
    } catch (\Webreboot\GdeSlon\Interface\Postback\PostbackException $e) {
        http_response_code($e->responseStatus());
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

        return true;
    }
    $conversion = $postback->conversion();
    echo json_encode([
        'format' => $postback->format()->value,
        'merchant_id' => $conversion->merchantId()->value(),
        'state' => $conversion->state()->value,
        'order_id' => $conversion->orderId()?->value(),
        'sub_ids' => (object) $conversion->subIds(),
        'reward' => $conversion->reward(),
        'offer_name' => $conversion->offerName(),
        'warnings' => $postback->warnings(),
    ], JSON_UNESCAPED_UNICODE);

    return true;
}

if (preg_match('~^/status/(\d{3})$~', $path, $match) === 1) {
    http_response_code((int) $match[1]);
    echo 'status ' . $match[1];

    return true;
}

if ($path === '/dup-headers') {
    header('X-Dup: first');
    header('X-Dup: second', false);
    echo 'dup';

    return true;
}

if ($path === '/gzip') {
    header('Content-Type: application/json');
    header('Content-Encoding: gzip');
    echo gzencode('{"gzip":true}');

    return true;
}

if (preg_match('~^/sleep/(\d+)$~', $path, $match) === 1) {
    usleep((int) $match[1] * 1000);
    echo 'slept';

    return true;
}

if ($path === '/truncated') {
    header('Content-Length: 1000');
    echo str_repeat('x', 10);

    return true;
}

if ($path === '/ranged') {
    $body = substr(str_repeat('{"категория":"Всё для шитья"}', 1500), 0, 40000);
    $etag = '"ranged-1"';
    $total = strlen($body);

    if (isset($_GET['ignore'])) {
        header('Content-Type: application/json');
        echo $body;

        return true;
    }

    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers['if-none-match'] ?? null) === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);

        return true;
    }
    if (isset($headers['if-match']) && $headers['if-match'] !== $etag) {
        http_response_code(412);

        return true;
    }

    header('Content-Type: application/json');
    header('ETag: ' . $etag);
    header('Last-Modified: Fri, 01 Nov 2024 10:37:41 GMT');
    $range = $headers['range'] ?? '';
    if (is_string($range) && preg_match('~^bytes=(\d+)-(\d+)$~', $range, $match) === 1) {
        $start = (int) $match[1];
        $end = min((int) $match[2], $total - 1);
        if ($start >= $total) {
            http_response_code(416);
            header('Content-Range: bytes */' . $total);

            return true;
        }
        http_response_code(206);
        header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $total));
        header('Content-Length: ' . ($end - $start + 1));
        echo substr($body, $start, $end - $start + 1);

        return true;
    }

    header('Content-Length: ' . $total);
    echo $body;

    return true;
}

if ($path === '/redirect') {
    header('Location: /json', true, 302);

    return true;
}

http_response_code(404);
echo 'Cannot GET ' . $uri;

return true;
