<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Catalog;

use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\MerchantRepository;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\ConditionalLoader;
use Webreboot\GdeSlon\Infrastructure\Cache\DocumentCache;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;

/** @internal */
final class HttpMerchantRepository implements MerchantRepository
{
    public const DEFAULT_URL = 'https://www.gdeslon.ru/api/users/shops.xml';

    private readonly MerchantXmlParser $parser;

    private readonly ConditionalLoader $loader;

    public function __construct(
        ApiClient $client,
        #[\SensitiveParameter]
        private readonly ?string $token = null,
        ?DocumentCache $cache = null,
        private readonly string $url = self::DEFAULT_URL,
    ) {
        $this->parser = new MerchantXmlParser();
        $this->loader = new ConditionalLoader($client, $cache);
    }

    public function all(bool $public = false, bool $refresh = false): MerchantList
    {
        $token = $public ? null : ($this->token ?? throw new InvalidArgumentException(
            'Магазины вебмастера требуют токен XML API: new Config(apiToken: …); публичный каталог без партнёрских ссылок — merchants(public: true)',
        ));
        $request = HttpRequest::get($this->url, $token === null ? [] : ['api_token' => $token]);
        $loaded = $this->loader->load(
            $request,
            $this->url . '#' . hash('sha256', $token ?? ''),
            fn (#[\SensitiveParameter] HttpResponse $response): MerchantList => $this->toList($request, $response, $public),
            static fn (MerchantList $list): bool => $list->skipped() === [],
            $refresh,
        );

        return $loaded->value->withOrigin($loaded->origin);
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        if ($this->token !== null) {
            throw InvalidArgumentException::withoutTraceArguments('Клиент с токеном API не сериализуется: создавайте его заново из настроек');
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

    private function toList(#[\SensitiveParameter] HttpRequest $request, #[\SensitiveParameter] HttpResponse $response, bool $public): MerchantList
    {
        $list = $this->parser->parse($response->body());

        if (!$public && count($list) > 0 && $list->filter(static fn (Merchant $m): bool => $m->affiliateLink() !== null) === []) {
            throw new AuthenticationException(
                sprintf(
                    '%s %s: токен XML API не принят: в ответе нет партнёрских ссылок (неверный токен или нет подключённых программ)',
                    $request->method(),
                    $request->maskedUri(),
                ),
                $response->statusCode(),
                $request->method(),
                $request->maskedUri(),
                '',
            );
        }

        return $list;
    }
}
