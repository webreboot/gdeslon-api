<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api;

use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Cache\CachedDocument;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;

/** @internal */
final class ConditionalLoader
{
    public function __construct(
        private readonly ApiClient $client,
        private readonly ?DocumentCache $cache = null,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(HttpResponse): T $parse
     * @param (callable(T): bool)|null  $cacheable
     *
     * @return T
     */
    public function load(HttpRequest $request, string $cacheId, callable $parse, ?callable $cacheable = null): mixed
    {
        $cache = $this->cache;
        $hit = $cache === null ? null : $this->cached($cache, $cacheId, $parse);
        if ($cache !== null && $hit !== null && $cache->isFresh($hit->document)) {
            return $hit->value;
        }

        $conditional = $hit === null ? $request : $request->withHeaders($request->headers() + self::conditionalHeaders($hit->document));
        try {
            $response = $this->client->fetch($conditional);
        } catch (TransportException $e) {
            return $hit !== null ? $hit->value : throw $e;
        } catch (HttpException $e) {
            if ($hit !== null && $e->statusCode() >= 500) {
                return $hit->value;
            }

            throw $e;
        }

        if ($response->statusCode() === 304) {
            if ($cache === null || $hit === null) {
                throw new UnexpectedResponseException(sprintf(
                    '%s %s: ответ 304 Not Modified, но сохранённой копии нет',
                    $request->method(),
                    $request->maskedUri(),
                ));
            }
            $cache->touch($cacheId, $hit->document);

            return $hit->value;
        }

        try {
            $result = $parse($response);
        } catch (UnexpectedResponseException $e) {
            return $hit !== null ? $hit->value : throw $e;
        }
        if ($cacheable === null || $cacheable($result)) {
            $cache?->save($cacheId, $response);
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param callable(HttpResponse): T $parse
     *
     * @return CacheHit<T>|null
     */
    private function cached(DocumentCache $cache, string $cacheId, callable $parse): ?CacheHit
    {
        $cached = $cache->find($cacheId);
        if ($cached === null) {
            return null;
        }

        try {
            return new CacheHit($cached, $parse(new HttpResponse(200, $cached->body(), [])));
        } catch (UnexpectedResponseException) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function conditionalHeaders(CachedDocument $cached): array
    {
        $headers = [];
        if ($cached->etag() !== null) {
            $headers['If-None-Match'] = $cached->etag();
        }
        if ($cached->lastModified() !== null) {
            $headers['If-Modified-Since'] = $cached->lastModified();
        }

        return $headers;
    }
}
