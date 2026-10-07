<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Claims;

use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaims;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Clock\SystemClock;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\MultipartFormData;

/**
 * Заявки на потерянные заказы: `https://gdeslon.ru/api/v1/lost-orders/`, `Authorization: Bearer <токен XML API>`
 * (docs/gdeslon-api/lost-orders.md). Адреса — только со слэшем на конце (без него — 301).
 *
 * Создание (POST multipart) отправляет РЕАЛЬНУЮ заявку рекламодателю и никогда не повторяется: если исход неизвестен
 * (таймаут, обрыв после отправки, 5xx, нечитаемый ответ) — LostOrderClaimUnconfirmedException; ошибка соединения до
 * отправки — TransportException (заявка точно не создана). Кэша нет.
 *
 * @internal используйте GdeSlon::lostOrders(), lostOrder(), submitLostOrderClaim()
 */
final class HttpLostOrderClaims implements LostOrderClaims
{
    public const DEFAULT_URL = 'https://gdeslon.ru/api/v1/lost-orders/';

    private const TIMEZONE = 'Europe/Moscow';

    /** cURL: соединение не установлено — запрос точно не отправлен (DNS, прокси, отказ, TLS-рукопожатие, сертификат). */
    private const NOT_SENT_CURL_ERRORS = [5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 66, 77, 83, 90, 91];

    private readonly Clock $clock;

    private readonly LostOrderClaimMapper $mapper;

    /**
     * @param (\Closure(): string)|null $boundary генератор границы multipart (для тестов)
     */
    public function __construct(
        private readonly ApiClient $client,
        #[\SensitiveParameter]
        private readonly ?string $token = null,
        ?Clock $clock = null,
        private readonly string $url = self::DEFAULT_URL,
        private readonly ?\Closure $boundary = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->mapper = new LostOrderClaimMapper();
    }

    public function find(LostOrderCriteria $criteria): LostOrderClaimList
    {
        $query = array_filter([
            'merchant_id' => $criteria->merchant()?->value(),
            'start_date' => $criteria->from(),
            'end_date' => $criteria->until(),
            'ticket_state' => $criteria->claimState()?->value,
            'order_status' => $criteria->orderStatus()?->value,
        ], static fn (int|string|null $value): bool => $value !== null);
        $request = HttpRequest::get($this->url, $query, $this->headers());

        $response = $this->client->requestAllowing($request, 400);
        if ($response->statusCode() === 400) {
            throw $this->validationError($request, $response);
        }
        if (str_starts_with(ltrim($response->body()), '{') && trim($response->body(), " \t\r\n{}") === '') {
            // json_decode(…, true) превращает «{}» в [], неотличимый от «нет заявок»
            throw new UnexpectedResponseException(sprintf('GET %s: ожидался список заявок, получен пустой объект', $request->maskedUri()));
        }

        return $this->mapper->toList($this->client->decodeJson($request, $response), $criteria);
    }

    public function get(LostOrderClaimId $id): ?LostOrderClaim
    {
        $request = HttpRequest::get($this->url . $id->value() . '/', [], $this->headers());

        $response = $this->client->requestAllowing($request, 404);
        if ($response->statusCode() === 404) {
            if ($this->mapper->errors($response->body()) !== null) {
                return null;
            }

            throw self::httpError($request, $response);
        }

        $claim = $this->mapper->toClaim($this->client->decodeJson($request, $response));
        if (!$claim->id()->equals($id)) {
            throw new UnexpectedResponseException(sprintf('GET %s: в ответе заявка %d вместо %d', $request->maskedUri(), $claim->id()->value(), $id->value()));
        }

        return $claim;
    }

    public function submit(NewLostOrderClaim $claim): LostOrderClaim
    {
        $headers = $this->headers();
        $claim->assertOrderDateWithin($this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE)));

        $form = ($this->boundary === null ? MultipartFormData::create() : MultipartFormData::create($this->boundary))
            ->withField('order_id', $claim->orderNumber())
            ->withField('order_date', $claim->orderDate())
            ->withField('order_total', $claim->orderTotal()->amount())
            ->withField('merchant_id', (string) $claim->merchant()->value());
        if ($claim->description() !== null) {
            $form = $form->withField('description', $claim->description());
        }
        $attachment = $claim->attachment();
        $form = $form->withFile('attachment', $attachment->fileName(), $attachment->type()->mediaType(), $attachment->contents());

        $request = HttpRequest::post($this->url, $form->body(), $headers + ['Content-Type' => $form->contentType()]);

        try {
            $response = $this->client->requestAllowing($request, 400);
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (HttpException $e) {
            // 4xx — сервер отказал, заявка не создана; 5xx — исход неизвестен
            throw $e->statusCode() >= 500 ? new LostOrderClaimUnconfirmedException($e) : $e;
        } catch (TimeoutException $e) {
            throw new LostOrderClaimUnconfirmedException($e);
        } catch (TransportException $e) {
            throw in_array($e->curlErrorCode(), self::NOT_SENT_CURL_ERRORS, true) ? $e : new LostOrderClaimUnconfirmedException($e);
        }

        if ($response->statusCode() === 400) {
            throw $this->validationError($request, $response);
        }
        try {
            $payload = $this->client->decodeJson($request, $response);
        } catch (UnexpectedResponseException $e) {
            throw new LostOrderClaimUnconfirmedException($e, $response->statusCode());
        }
        try {
            return $this->mapper->toClaim($payload);
        } catch (UnexpectedResponseException $e) {
            // 2xx — заявка создана, хотя ответ разошёлся с документацией: отдаём хотя бы её ID
            $id = is_array($payload) ? ($payload['id'] ?? null) : null;
            if (is_string($id) && preg_match('/^\d{1,18}\z/', $id) === 1) {
                $id = (int) $id;
            }

            throw new LostOrderClaimUnconfirmedException($e, $response->statusCode(), is_int($id) && $id > 0 ? $id : null);
        }
    }

    /**
     * С токеном не сериализуется: токен не должен попасть в очереди, сессии, файлы.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        if ($this->token !== null) {
            throw new InvalidArgumentException('Клиент с токеном API не сериализуется: создавайте его заново из настроек');
        }

        return array_keys(get_object_vars($this));
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'token' => $this->token === null ? null : '***'];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        if ($this->token === null) {
            throw new InvalidArgumentException('Потерянные заказы требуют токен XML API: new Config(apiToken: …)');
        }
        // значение уходит в заголовок: перевод строки подменил бы заголовки запроса
        if (preg_match('/^[\x21-\x7E]+\z/', $this->token) !== 1) {
            throw new InvalidArgumentException('Неверный токен XML API: допустимы только печатные символы ASCII без пробелов');
        }

        return ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'];
    }

    private function validationError(HttpRequest $request, HttpResponse $response): HttpException
    {
        $errors = $this->mapper->errors($response->body());
        if ($errors === null) {
            return self::httpError($request, $response);
        }

        return new LostOrderValidationException(
            // сообщения сервера повторяют значения запроса (номер заказа, дату) — в тексте только имена полей
            sprintf('%s %s: HTTP 400 — ошибки полей: %s (подробности — errors())', $request->method(), $request->maskedUri(), implode(', ', array_keys($errors))),
            $response->statusCode(),
            $request->method(),
            $request->maskedUri(),
            ApiClient::maskedSnippet($request, $response),
            $errors,
        );
    }

    private static function httpError(HttpRequest $request, HttpResponse $response): HttpException
    {
        return new HttpException(
            sprintf('%s %s: HTTP %d', $request->method(), $request->maskedUri(), $response->statusCode()),
            $response->statusCode(),
            $request->method(),
            $request->maskedUri(),
            ApiClient::maskedSnippet($request, $response),
        );
    }
}
