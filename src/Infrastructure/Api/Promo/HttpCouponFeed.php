<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Promo;

use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteria;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteriaRejectedException;
use Webreboot\GdeSlon\Domain\Promo\CouponFeed;
use Webreboot\GdeSlon\Domain\Promo\CouponList;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;

/**
 * @internal
 */
final class HttpCouponFeed implements CouponFeed
{
    public const DEFAULT_URL = 'https://gdeslon.ru/api/coupons.xml';

    private readonly CouponXmlParser $parser;

    public function __construct(
        private readonly ApiClient $client,
        #[\SensitiveParameter]
        private readonly ?string $token = null,
        private readonly string $url = self::DEFAULT_URL,
    ) {
        $this->parser = new CouponXmlParser();
    }

    public function find(CouponCriteria $criteria): CouponList
    {
        if ($this->token === null) {
            throw new InvalidArgumentException('Купоны требуют токен XML API: new Config(apiToken: …)');
        }

        $request = HttpRequest::get($this->url, [
            'api_token' => $this->token,
            'merchant_id' => array_map(static fn (MerchantId $merchant): int => $merchant->value(), $criteria->merchants()),
            'kind' => $criteria->kinds(),
        ]);

        $response = $this->client->requestAllowing($request, 400);
        if ($response->statusCode() === 400) {
            $errors = $this->parser->errors($response->body());
            $snippet = ApiClient::maskedSnippet($request, $response);
            if ($errors === null) {
                throw new HttpException(sprintf('GET %s: HTTP 400', $request->maskedUri()), 400, 'GET', $request->maskedUri(), $snippet);
            }

            throw new CouponCriteriaRejectedException(
                sprintf('GET %s: HTTP 400 — фильтр отклонён: %s (подробности — errors())', $request->maskedUri(), implode(', ', array_keys($errors))),
                400,
                'GET',
                $request->maskedUri(),
                $snippet,
                $errors,
            );
        }

        try {
            return $this->parser->parse($response->body(), $criteria);
        } catch (UnexpectedResponseException $e) {
            throw new UnexpectedResponseException($e->getMessage());
        }
    }

    /**
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
}
