<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Cache;

use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;

/**
 * Кэш ответов публичных адресов API с проверкой свежести по времени.
 *
 * Идентификатор записи — URL без секретов; если ответ зависит от секрета (токена), добавляйте только его хэш:
 * `url#sha256(token)` — так кэши разных вебмастеров разделены, а сам секрет в хранилище не попадает.
 *
 * @internal
 */
final class DocumentCache
{
    /**
     * @param int $ttl сколько секунд запись считается свежей; 0 — всегда проверять у сервера
     */
    public function __construct(
        private readonly CacheStore $store,
        private readonly Clock $clock,
        private readonly int $ttl,
    ) {
        if ($ttl < 0) {
            throw new InvalidArgumentException(sprintf('Время жизни кэша не может быть отрицательным, получено %d', $ttl));
        }
    }

    public static function key(string $url): string
    {
        return 'gdeslon_' . sha1($url);
    }

    /**
     * @phpstan-impure читает хранилище: результат меняется после save()/touch()
     */
    public function find(string $url): ?CachedDocument
    {
        $value = $this->store->get(self::key($url));

        return $value === null ? null : CachedDocument::fromString($value);
    }

    /**
     * Свежая — моложе ttl. Запись «из будущего» (часы ушли назад) свежей не считается.
     */
    public function isFresh(CachedDocument $document): bool
    {
        $age = $this->clock->now()->getTimestamp() - $document->storedAt()->getTimestamp();

        return $age >= 0 && $age < $this->ttl;
    }

    /**
     * @return bool записан ли ответ в хранилище
     */
    public function save(string $url, HttpResponse $response): bool
    {
        return $this->write($url, new CachedDocument(
            $response->body(),
            $response->header('etag'),
            $response->header('last-modified'),
            $this->clock->now(),
        ));
    }

    /**
     * Сервер подтвердил, что запись не изменилась (304): она снова свежая.
     */
    public function touch(string $url, CachedDocument $document): void
    {
        $this->write($url, $document->withStoredAt($this->clock->now()));
    }

    private function write(string $url, CachedDocument $document): bool
    {
        try {
            return $this->store->set(self::key($url), $document->toString());
        } catch (\JsonException) {
            return false;
        }
    }
}
