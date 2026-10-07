<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Psr\Http\Message\ServerRequestInterface;

final class PostbackRequest
{
    private readonly string $method;

    /** @var array<string, list<string>> */
    private readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed>|null          $form
     */
    public function __construct(
        string $method,
        #[\SensitiveParameter]
        array $headers = [],
        private readonly string $query = '',
        private readonly string $body = '',
        private readonly ?array $form = null,
    ) {
        $this->method = strtoupper(trim($method));

        $normalized = [];
        foreach ($headers as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $normalized[strtolower(trim((string) $name))][] = (string) $value;
            }
        }
        $this->headers = $normalized;
    }

    /** @throws InvalidPostbackException */
    public static function fromGlobals(int $maxBodyBytes = PostbackReceiver::DEFAULT_MAX_BODY_BYTES): self
    {
        $input = fopen('php://input', 'rb');
        try {
            /** @var array<string, mixed> $server */
            $server = $_SERVER;
            /** @var array<string, mixed> $post */
            $post = $_POST;

            return self::fromServer($server, $post, $input === false ? null : $input, $maxBodyBytes);
        } finally {
            if ($input !== false) {
                fclose($input);
            }
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $post
     * @param resource|null        $input
     *
     * @throws InvalidPostbackException
     */
    public static function fromServer(#[\SensitiveParameter] array $server, array $post = [], $input = null, int $maxBodyBytes = PostbackReceiver::DEFAULT_MAX_BODY_BYTES): self
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = $key;
            } else {
                continue;
            }
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))))] = (string) $value;
        }

        $length = $headers['Content-Length'] ?? null;
        if ($length !== null && preg_match('/^\d{1,18}$/', $length) === 1 && (int) $length > $maxBodyBytes) {
            throw PostbackReceiver::tooLarge($maxBodyBytes);
        }
        $body = '';
        if ($input !== null) {
            $body = (string) stream_get_contents($input, $maxBodyBytes + 1);
            if (strlen($body) > $maxBodyBytes) {
                throw PostbackReceiver::tooLarge($maxBodyBytes);
            }
        }

        $method = $server['REQUEST_METHOD'] ?? 'GET';
        // не $_GET: PHP заменяет точки и пробелы в именах параметров на «_»
        $query = $server['QUERY_STRING'] ?? '';

        return new self(
            is_string($method) ? $method : 'GET',
            $headers,
            is_string($query) ? $query : '',
            $body,
            $post === [] ? null : $post,
        );
    }

    /** @throws InvalidPostbackException */
    public static function fromPsr7(#[\SensitiveParameter] ServerRequestInterface $request, int $maxBodyBytes = PostbackReceiver::DEFAULT_MAX_BODY_BYTES): self
    {
        $length = $request->getHeaderLine('Content-Length');
        if (preg_match('/^\d{1,18}$/', $length) === 1 && (int) $length > $maxBodyBytes) {
            throw PostbackReceiver::tooLarge($maxBodyBytes);
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while (!$stream->eof() && strlen($body) <= $maxBodyBytes) {
            $chunk = $stream->read(min(8192, $maxBodyBytes + 1 - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        if (strlen($body) > $maxBodyBytes) {
            throw PostbackReceiver::tooLarge($maxBodyBytes);
        }

        $form = $request->getParsedBody();
        $parsed = [];
        if (is_array($form)) {
            foreach ($form as $name => $value) {
                $parsed[(string) $name] = $value;
            }
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map(strval(...), $values));
        }

        return new self(
            $request->getMethod(),
            $headers,
            $request->getUri()->getQuery(),
            $body,
            $parsed === [] ? null : $parsed,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function header(string $name): ?string
    {
        return $this->headerValues($name)[0] ?? null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower(trim($name))] ?? [];
    }

    public function query(): string
    {
        return $this->query;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed>|null */
    public function form(): ?array
    {
        return $this->form;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $values) {
            $headers[$name] = in_array($name, ['content-type', 'content-length'], true) ? $values : '***';
        }

        return [
            'method' => $this->method,
            'headers' => $headers,
            'query' => $this->query,
            'body' => $this->body,
            'form' => $this->form,
        ];
    }
}
