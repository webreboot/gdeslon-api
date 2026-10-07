<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Api\Sales;

use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Sales\OrderRepository;
use Webreboot\GdeSlon\Domain\Sales\OrderState;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Clock\SystemClock;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;

/** @internal */
final class HttpOrderRepository implements OrderRepository
{
    public const DEFAULT_URL = 'https://gdeslon.ru/api/orders/';

    private const TIMEZONE = 'Europe/Moscow';

    private readonly Clock $clock;

    private readonly OrderMapper $mapper;

    public function __construct(
        private readonly ApiClient $client,
        private readonly ?string $userId = null,
        #[\SensitiveParameter]
        private readonly ?string $apiKey = null,
        ?Clock $clock = null,
        private readonly string $url = self::DEFAULT_URL,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->mapper = new OrderMapper();
    }

    public function find(OrderCriteria $criteria): OrderList
    {
        if ($this->userId === null || $this->apiKey === null) {
            throw new InvalidArgumentException(
                'Заказы требуют ключи API по продажам (https://gdeslon.ru/api_settings/orders): new Config(userId: …, apiKey: …)',
            );
        }

        $request = HttpRequest::post($this->url, $this->body($criteria), [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->userId . ':' . $this->apiKey),
        ]);

        $response = $this->client->request($request);
        if (str_starts_with(ltrim($response->body()), '{')) {
            throw new UnexpectedResponseException(sprintf(
                '%s %s: ожидался JSON-массив заказов, получен объект (%d байт)',
                $request->method(),
                $request->maskedUri(),
                strlen($response->body()),
            ));
        }

        return $this->mapper->toList($this->client->decodeJson($request, $response), $criteria);
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        if ($this->apiKey !== null) {
            throw new InvalidArgumentException('Клиент с ключом API по продажам не сериализуется: создавайте его заново из настроек');
        }

        return array_keys(get_object_vars($this));
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'url' => $this->url,
            'userId' => $this->userId === null ? null : '***',
            'apiKey' => $this->apiKey === null ? null : '***',
        ];
    }

    private function body(OrderCriteria $criteria): string
    {
        $until = $criteria->until()
            ?? $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');

        // типы JSON важны: period и merchant_id строкой API отвергает ответом 500
        $filter = [$criteria->dateField()->value => ['date' => $until, 'period' => $criteria->days()]];
        if ($criteria->merchant() !== null) {
            $filter['merchant_id'] = $criteria->merchant()->value();
        }
        if ($criteria->states() !== []) {
            $filter['state'] = array_map(static fn (OrderState $state): int => $state->value, $criteria->states());
        }
        if ($criteria->type() !== null) {
            $filter['type'] = $criteria->type()->value;
        }
        if ($criteria->subId() !== null) {
            $filter['sub_id'] = $criteria->subId();
        }

        return json_encode($filter, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
