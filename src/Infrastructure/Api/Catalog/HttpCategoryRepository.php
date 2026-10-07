<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\CategoryRepository;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\ConditionalLoader;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;

/** @internal */
final class HttpCategoryRepository implements CategoryRepository
{
    public const DEFAULT_URL = 'https://api.gdeslon.ru/gdeslon-categories.json';

    private readonly CategoryMapper $mapper;

    private readonly ConditionalLoader $loader;

    public function __construct(
        private readonly ApiClient $client,
        private readonly string $url = self::DEFAULT_URL,
        ?DocumentCache $cache = null,
    ) {
        $this->mapper = new CategoryMapper();
        $this->loader = new ConditionalLoader($client, $cache);
    }

    public function all(): CategoryTree
    {
        $request = HttpRequest::get($this->url);

        return $this->loader->load(
            $request,
            $this->url,
            fn (HttpResponse $response): CategoryTree => $this->mapper->toTree($this->client->decodeJson($request, $response)),
        );
    }
}
