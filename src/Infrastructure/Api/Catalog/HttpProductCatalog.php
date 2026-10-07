<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\CategoryId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\ProductCatalog;
use Webreboot\GdeSlon\Domain\Catalog\SearchCriteria;
use Webreboot\GdeSlon\Domain\Catalog\SearchResult;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;

/** @internal */
final class HttpProductCatalog implements ProductCatalog
{
    public const DEFAULT_URL = 'https://api.gdeslon.ru/api/search.xml';

    private readonly OfferXmlParser $parser;

    public function __construct(
        private readonly ApiClient $client,
        private readonly ?string $token = null,
        private readonly string $url = self::DEFAULT_URL,
    ) {
        $this->parser = new OfferXmlParser();
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        if ($this->token === null) {
            throw new InvalidArgumentException('Поиск товаров требует токен XML API: new Config(apiToken: …)');
        }

        $request = HttpRequest::get($this->url, self::query($this->token, $criteria));
        try {
            $response = $this->client->fetch($request);
        } catch (HttpException $e) {
            if ($e->statusCode() === 404 && str_contains($e->responseSnippet(), "param: '_gs_at'")) {
                throw new AuthenticationException(
                    sprintf('%s %s: HTTP 404 — токен XML API неверного формата', $e->method(), $e->url()),
                    $e->statusCode(),
                    $e->method(),
                    $e->url(),
                    $e->responseSnippet(),
                    $e,
                );
            }

            throw $e;
        } catch (TimeoutException $e) {
            throw new TimeoutException(
                sprintf(
                    '%s. Ответ поиска из некоторых сетей обрывается после ~16 КБ: уменьшите limit (сейчас %d) или увеличьте Config::timeout',
                    rtrim($e->getMessage(), '.'),
                    $criteria->limit(),
                ),
                $e->method(),
                $e->url(),
                $e->curlErrorCode(),
                $e,
            );
        }

        return $this->parser->parse($response->body(), $criteria);
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

    /**
     * @return array<string, scalar>
     */
    private static function query(string $token, SearchCriteria $criteria): array
    {
        $query = ['_gs_at' => $token, 'l' => $criteria->limit(), 'p' => $criteria->page()];
        $optional = [
            'q' => $criteria->query() ?? '',
            'm' => self::ids($criteria->merchants()),
            'no_m' => self::ids($criteria->excludedMerchants()),
            'tid' => self::ids($criteria->categories()),
            'no_tid' => self::ids($criteria->excludedCategories()),
            'articles' => implode(',', $criteria->articles()),
            'order' => $criteria->sort() === null ? '' : $criteria->sort()->value,
            'parked_domain_name' => $criteria->parkedDomain() ?? '',
        ];

        return $query + array_filter($optional, static fn (string $value): bool => $value !== '');
    }

    /**
     * @param list<MerchantId>|list<CategoryId> $ids
     */
    private static function ids(array $ids): string
    {
        $values = [];
        foreach ($ids as $id) {
            $values[] = $id->value();
        }

        return implode(',', $values);
    }
}
