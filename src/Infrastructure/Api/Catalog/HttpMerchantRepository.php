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

/**
 * Магазины из shops.xml (docs/gdeslon-api/merchants.md): с токеном XML API — магазины вебмастера с партнёрскими
 * ссылками, без токена — публичный каталог.
 *
 * API не сообщает о неверном токене: отвечает 200 и публичным каталогом. Поэтому ответ на запрос с токеном, где нет ни
 * одной партнёрской ссылки, считается отказом в доступе (AuthenticationException) и в кэш не попадает.
 *
 * @internal используйте GdeSlon::merchants()
 */
final class HttpMerchantRepository implements MerchantRepository
{
    public const DEFAULT_URL = 'https://www.gdeslon.ru/api/users/shops.xml';

    private readonly MerchantXmlParser $parser;

    private readonly ConditionalLoader $loader;

    public function __construct(
        ApiClient $client,
        private readonly ?string $token = null,
        ?DocumentCache $cache = null,
        private readonly string $url = self::DEFAULT_URL,
    ) {
        $this->parser = new MerchantXmlParser();
        $this->loader = new ConditionalLoader($client, $cache);
    }

    public function all(): MerchantList
    {
        $request = HttpRequest::get($this->url, $this->token === null ? [] : ['api_token' => $this->token]);

        return $this->loader->load(
            $request,
            // токен — только хэшем: кэши разных вебмастеров разделены, а сам токен в хранилище не попадает
            $this->url . '#' . hash('sha256', $this->token ?? ''),
            fn (HttpResponse $response): MerchantList => $this->toList($request, $response),
            // ответ с пропущенными (битыми) записями не вытесняет рабочую копию и не кэшируется
            static fn (MerchantList $list): bool => $list->skipped() === [],
        );
    }

    /**
     * С токеном не сериализуется (в т.ч. в составе GdeSlon): токен не должен попасть в очереди, сессии, файлы.
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
     * Токен не показывается в var_dump()/print_r() (в т.ч. при выводе GdeSlon).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'token' => $this->token === null ? null : '***'];
    }

    private function toList(HttpRequest $request, HttpResponse $response): MerchantList
    {
        $list = $this->parser->parse($response->body());

        if ($this->token !== null && count($list) > 0 && $list->filter(static fn (Merchant $m): bool => $m->affiliateLink() !== null) === []) {
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
