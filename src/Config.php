<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Настройки клиента. Новые параметры добавляются только необязательными в конец (вызывайте с именами аргументов).
 */
final class Config
{
    private readonly ?string $userId;

    private readonly ?string $apiKey;

    /**
     * @param float       $timeout        общий таймаут запроса, секунды
     * @param float       $connectTimeout таймаут установки соединения, секунды
     * @param string|null $userAgent      свой User-Agent; null — имя и версия пакета
     * @param int|null    $rangeChunkSize размер части при загрузке частями с api.gdeslon.ru (байт, ≥ 1024);
     *                                    null — загружать одним запросом
     * @param int         $cacheTtl       сколько секунд кэш категорий считается свежим; 0 — проверять каждый раз
     * @param string|null $apiToken       токен XML API (https://gdeslon.ru/api_settings/xml); null — публичные данные
     * @param int         $merchantCacheTtl сколько секунд кэш магазинов считается свежим; 0 — проверять каждый раз
     * @param int|string|null $userId     ID пользователя API по продажам (https://gdeslon.ru/api_settings/orders) —
     *                                    для заказов; задаётся вместе с $apiKey
     * @param string|null $apiKey         ключ API по продажам (там же; не токен XML API)
     */
    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        private readonly ?string $userAgent = null,
        private readonly ?int $rangeChunkSize = 16384,
        private readonly int $cacheTtl = 86400,
        #[\SensitiveParameter]
        private readonly ?string $apiToken = null,
        private readonly int $merchantCacheTtl = 3600,
        #[\SensitiveParameter]
        int|string|null $userId = null,
        #[\SensitiveParameter]
        ?string $apiKey = null,
    ) {
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Таймауты должны быть положительными, получено timeout=%s, connectTimeout=%s',
                $timeout,
                $connectTimeout,
            ));
        }
        if ($rangeChunkSize !== null && $rangeChunkSize < 1024) {
            throw new InvalidArgumentException(sprintf('Размер части должен быть не меньше 1024 байт, получено %d', $rangeChunkSize));
        }
        if ($cacheTtl < 0 || $merchantCacheTtl < 0) {
            throw new InvalidArgumentException(sprintf(
                'Время жизни кэша не может быть отрицательным, получено cacheTtl=%d, merchantCacheTtl=%d',
                $cacheTtl,
                $merchantCacheTtl,
            ));
        }
        if ($apiToken !== null && trim($apiToken) === '') {
            throw new InvalidArgumentException('Токен XML API пуст: передайте токен или null');
        }

        if (($userId === null) !== ($apiKey === null)) {
            throw new InvalidArgumentException('ID пользователя и ключ API по продажам задаются вместе (userId и apiKey)');
        }
        $userId = $userId === null ? null : trim((string) $userId);
        if ($userId !== null && (preg_match('/^\d{1,19}\z/', $userId) !== 1 || ltrim($userId, '0') === '')) {
            throw new InvalidArgumentException('ID пользователя API по продажам должен быть положительным числом');
        }
        $apiKey = $apiKey === null ? null : trim($apiKey);
        if ($apiKey === '') {
            throw new InvalidArgumentException('Ключ API по продажам пуст: передайте ключ или null');
        }
        if ($apiKey !== null && str_contains($apiKey, ':')) {
            throw new InvalidArgumentException('Ключ API по продажам не может содержать двоеточие (HTTP Basic)');
        }
        $this->userId = $userId;
        $this->apiKey = $apiKey;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function rangeChunkSize(): ?int
    {
        return $this->rangeChunkSize;
    }

    public function cacheTtl(): int
    {
        return $this->cacheTtl;
    }

    /**
     * Токен XML API (https://gdeslon.ru/api_settings/xml): магазины вебмастера с партнёрскими ссылками.
     */
    public function apiToken(): ?string
    {
        return $this->apiToken;
    }

    public function merchantCacheTtl(): int
    {
        return $this->merchantCacheTtl;
    }

    /**
     * ID пользователя API по продажам (https://gdeslon.ru/api_settings/orders) — для заказов.
     */
    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * Ключ API по продажам (https://gdeslon.ru/api_settings/orders) — для заказов.
     */
    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * Config с токеном или ключом не сериализуется (секреты не должны попасть в сессии, очереди, файлы).
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        if ($this->apiToken !== null || $this->apiKey !== null) {
            throw new InvalidArgumentException('Config с токеном API не сериализуется: создавайте его заново из окружения');
        }

        return array_keys(get_object_vars($this));
    }

    /**
     * Токен, ID пользователя и ключ не показываются в var_dump()/print_r() (var_export() этот метод не учитывает).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'userAgent' => $this->userAgent,
            'rangeChunkSize' => $this->rangeChunkSize,
            'cacheTtl' => $this->cacheTtl,
            'apiToken' => $this->apiToken === null ? null : '***',
            'merchantCacheTtl' => $this->merchantCacheTtl,
            'userId' => $this->userId === null ? null : '***',
            'apiKey' => $this->apiKey === null ? null : '***',
        ];
    }
}
