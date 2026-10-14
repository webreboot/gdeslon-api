<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class FeedUrl implements \Stringable
{
    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];

    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $urls = null;

    private readonly string $fingerprint;

    private function __construct(
        #[\SensitiveParameter]
        string $url,
        private readonly string $masked,
    ) {
        // ссылку храним вне свойств объекта: var_export(), (array) и get_object_vars() её не покажут
        self::urls()[$this] = $url;
        $this->fingerprint = hash('sha256', $url);
    }

    public static function fromString(#[\SensitiveParameter] string $url): self
    {
        $url = trim($url);
        if ($url === '' || preg_match('~[\s\x00-\x1F\x7F]~', $url) === 1) {
            throw self::invalid();
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || trim($path, '/') === '') {
            throw self::invalid();
        }
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true))) {
            throw self::invalid();
        }

        $directory = substr($path, 0, (int) strrpos(rtrim($path, '/'), '/') + 1);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return new self($url, sprintf('%s://%s%s%s***', $scheme, $host, $port, $directory === '' ? '/' : $directory));
    }

    /** @internal */
    public function value(): string
    {
        return self::urls()[$this] ?? throw new InvalidArgumentException('Ссылка на выгрузку создаётся только через FeedUrl::fromString()');
    }

    public function masked(): string
    {
        return $this->masked;
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $url = $this->value();
        $name = basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));
        $stem = pathinfo($name, PATHINFO_FILENAME);

        return array_values(array_unique(array_filter([$url, $name, $stem], static fn (string $value): bool => strlen($value) >= 4)));
    }

    public function equals(#[\SensitiveParameter] self $other): bool
    {
        return hash_equals($this->fingerprint, $other->fingerprint);
    }

    public function __toString(): string
    {
        return $this->masked;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['url' => $this->masked];
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        throw InvalidArgumentException::withoutTraceArguments('Ссылка на выгрузку — секрет и не сериализуется: храните её как токен');
    }

    public function __wakeup(): void
    {
        throw InvalidArgumentException::withoutTraceArguments('Ссылка на выгрузку не десериализуется: создавайте её через FeedUrl::fromString()');
    }

    private function __clone()
    {
    }

    /**
     * @return \WeakMap<self, string>
     */
    private static function urls(): \WeakMap
    {
        if (self::$urls === null) {
            /** @var \WeakMap<self, string> $urls */
            $urls = new \WeakMap();
            self::$urls = $urls;
        }

        return self::$urls;
    }

    private static function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('Ссылка на выгрузку: ожидался адрес https:// с путём к файлу (http — только для 127.0.0.1 и localhost)');
    }
}
