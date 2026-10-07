<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/**
 * Приём postback «Где Слон?» (docs/gdeslon-api/postback.md): проверяет секрет, разбирает GET-параметры, форму, JSON
 * или XML и отдаёт конверсию.
 *
 * ```php
 * $receiver = new PostbackReceiver(new HeaderSecret('X-Gdeslon-Secret', getenv('GDESLON_POSTBACK_SECRET')));
 * try {
 *     $postback = $receiver->receive(PostbackRequest::fromGlobals());
 * } catch (PostbackException $e) {
 *     http_response_code($e->responseStatus());
 *     header('Content-Type: text/plain; charset=utf-8'); // сообщение — простой текст, не HTML
 *     exit($e->getMessage());
 * }
 * ```
 *
 * Порядок: метод (405) → секрет (401) → размер тела (413) → формат (415) → разбор (400). Формат — по Content-Type, а
 * без него (или text/plain) — по первому символу тела: «{» — JSON, «<» — XML, иначе форма. multipart/form-data и POST
 * без тела — разобранная форма (`PostbackRequest::form()`), POST без тела и формы — строка запроса.
 */
final class PostbackReceiver
{
    public const DEFAULT_MAX_BODY_BYTES = 65536;

    private const GUESSED_TYPES = ['', 'text/plain', 'application/octet-stream'];

    private readonly ConversionMapper $mapper;

    /**
     * @param HeaderSecret|null $secret       секрет из заголовка; null — без проверки (только если запрос защищён иначе)
     * @param PostbackFields    $fields       имена параметров, как они названы в кабинете
     * @param int               $maxBodyBytes больше — 413
     */
    public function __construct(
        private readonly ?HeaderSecret $secret,
        PostbackFields $fields = new PostbackFields(),
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
        $this->mapper = new ConversionMapper($fields);
    }

    /**
     * @throws PostbackException PostbackAuthenticationException (401) или InvalidPostbackException (400/405/413/415)
     */
    public function receive(#[\SensitiveParameter] PostbackRequest $request): ReceivedPostback
    {
        if ($request->method() !== 'GET' && $request->method() !== 'POST') {
            throw new InvalidPostbackException(sprintf('Postback: метод %s не поддерживается, ожидался GET или POST', PostbackText::safe($request->method())), 405);
        }
        $this->secret?->verify($request);
        if (strlen($request->body()) > $this->maxBodyBytes) {
            throw self::tooLarge($this->maxBodyBytes);
        }

        [$format, $parameters] = self::parameters($request);
        [$conversion, $warnings] = $this->mapper->map($parameters);

        return new ReceivedPostback($conversion, $format, $warnings, $parameters);
    }

    /**
     * @internal
     */
    public static function tooLarge(int $maxBodyBytes): InvalidPostbackException
    {
        return new InvalidPostbackException(sprintf('Postback: тело больше %d байт', $maxBodyBytes), 413);
    }

    /**
     * @return array{PostbackFormat, array<string, string|NonScalarValue>}
     */
    private static function parameters(#[\SensitiveParameter] PostbackRequest $request): array
    {
        $body = trim($request->body());
        if ($request->method() === 'GET' || $body === '') {
            $form = $request->form();
            if ($request->method() === 'POST' && $form !== null && $form !== []) {
                return [PostbackFormat::Form, self::formParameters($form)];
            }

            return [PostbackFormat::Query, QueryStringParser::parse($request->query())];
        }

        $type = strtolower(trim(explode(';', $request->header('Content-Type') ?? '')[0]));
        if ($type === 'multipart/form-data') {
            // сырое multipart-тело (Octane/Swoole, PSR-7 из ReactPHP) не разбираем — нужна форма, разобранная сервером
            $form = $request->form();
            if ($form === null || $form === []) {
                throw new InvalidPostbackException('Postback: multipart/form-data без разобранной формы — передайте её в PostbackRequest (form)');
            }

            return [PostbackFormat::Form, self::formParameters($form)];
        }
        $format = match (true) {
            $type === 'application/json' || str_ends_with($type, '+json') => PostbackFormat::Json,
            $type === 'application/xml' || $type === 'text/xml' || str_ends_with($type, '+xml') => PostbackFormat::Xml,
            $type === 'application/x-www-form-urlencoded' => PostbackFormat::Form,
            in_array($type, self::GUESSED_TYPES, true) => match ($body[0]) {
                '{' => PostbackFormat::Json,
                '<' => PostbackFormat::Xml,
                default => PostbackFormat::Form,
            },
            default => throw new InvalidPostbackException(
                sprintf('Postback: Content-Type %s не поддерживается, ожидались форма, JSON или XML', PostbackText::safe($type)),
                415,
            ),
        };

        return [$format, match ($format) {
            PostbackFormat::Json => JsonBodyParser::parse($body),
            PostbackFormat::Xml => XmlBodyParser::parse($body),
            default => QueryStringParser::parse($body),
        }];
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array<string, string|NonScalarValue>
     */
    private static function formParameters(array $form): array
    {
        $parameters = [];
        foreach ($form as $name => $value) {
            $parameters[(string) $name] = is_scalar($value) ? (string) $value : new NonScalarValue(get_debug_type($value));
        }

        return $parameters;
    }
}
