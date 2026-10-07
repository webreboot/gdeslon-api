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

/**
 * Загрузка документа API с необязательным кэшем.
 *
 * Свежая запись отдаётся без запроса; устаревшая проверяется условным запросом (ETag / Last-Modified → 304); при сбое
 * сети, 5xx или битом ответе (UnexpectedResponseException разбора) отдаётся устаревшая запись; 4xx и отказ в доступе
 * не прячутся. В кэш пишется только ответ, который разобран целиком; повреждённая запись считается промахом.
 *
 * @internal
 */
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
     * @param string                    $cacheId   ключ записи без секретов (секрет — только хэшем, см. DocumentCache)
     * @param callable(HttpResponse): T $parse     разбор ответа; бросает UnexpectedResponseException на битый ответ
     * @param (callable(T): bool)|null  $cacheable можно ли сохранить разобранный ответ (по умолчанию — да)
     *
     * @return T
     *
     * @throws GdeSlonException
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
            // битый ответ не сохраняется; есть рабочая устаревшая копия — отдаём её, как при сбое сети
            return $hit !== null ? $hit->value : throw $e;
        }
        if ($cacheable === null || $cacheable($result)) {
            $cache?->save($cacheId, $response);
        }

        return $result;
    }

    /**
     * Запись кэша и разобранное значение; повреждённая или неразбираемая запись — промах (null).
     *
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
