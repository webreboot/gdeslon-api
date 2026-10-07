<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Входящий HTTP-запрос postback без привязки к фреймворку. Неизменяемый. var_dump/print_r не показывают значения
 * заголовков (в одном из них — секрет), кроме Content-Type и Content-Length.
 *
 * Тело — как пришло (JSON, XML, urlencoded); `form` — уже разобранная фреймворком форма (multipart), если тела нет.
 */
final class PostbackRequest
{
    private readonly string $method;

    /** @var array<string, list<string>> имя в нижнем регистре → значения */
    private readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers заголовки «имя => значение или значения»
     * @param string                             $query   строка запроса как есть, без «?»
     * @param array<string, mixed>|null          $form    разобранная форма ($_POST), если тело недоступно
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

    /**
     * Запрос из суперглобалов PHP ($_SERVER, $_POST, php://input). Тело читается не больше $maxBodyBytes + 1 байт.
     *
     * @throws InvalidPostbackException тело больше $maxBodyBytes (413)
     */
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
     * Запрос из массива в формате $_SERVER: метод, заголовки (HTTP_*, CONTENT_TYPE, CONTENT_LENGTH), сырая строка
     * запроса QUERY_STRING (не $_GET: PHP искажает имена с точками и пробелами) и тело из потока.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $post   разобранная форма ($_POST) — нужна для multipart, где тела в php://input нет
     * @param resource|null        $input
     *
     * @throws InvalidPostbackException тело больше $maxBodyBytes (413)
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
        $query = $server['QUERY_STRING'] ?? '';

        return new self(
            is_string($method) ? $method : 'GET',
            $headers,
            is_string($query) ? $query : '',
            $body,
            $post === [] ? null : $post,
        );
    }

    /**
     * Запрос из PSR-7 (Laravel, Symfony через psr-http-message-bridge, Slim…). Нужен пакет psr/http-message. Тело
     * читается не больше $maxBodyBytes + 1 байт; уже прочитанный фреймворком поток перематывается, если это возможно.
     *
     * @throws InvalidPostbackException тело больше $maxBodyBytes (413)
     */
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

    /**
     * Первое значение заголовка без учёта регистра имени.
     */
    public function header(string $name): ?string
    {
        return $this->headerValues($name)[0] ?? null;
    }

    /**
     * @return list<string>
     */
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

    /**
     * @return array<string, mixed>|null
     */
    public function form(): ?array
    {
        return $this->form;
    }

    /**
     * @return array<string, mixed>
     */
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
