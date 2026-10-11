<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Domain\Shared\AdMarking;
use Webreboot\GdeSlon\Domain\Shared\HttpUrl;
use Webreboot\GdeSlon\Domain\Shared\SubId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class AffiliateLink implements \Stringable
{
    private function __construct(private readonly string $url)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(#[\SensitiveParameter] string $url): self
    {
        if (!HttpUrl::isValid($url) || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new InvalidArgumentException('Партнёрская ссылка: ожидался адрес http(s):// без пробелов и управляющих символов');
        }

        return new self($url);
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function withSubId(#[\SensitiveParameter] string|SubId $subId, int $position = 1): self
    {
        $name = self::subIdName($position);
        $pair = $name . '=' . ($subId instanceof SubId ? $subId : new SubId($subId))->value();
        [$base, $query, $fragment] = $this->parts();

        if ($query === null) {
            return new self($base . '?' . $pair . $fragment);
        }

        $pairs = [];
        $replaced = false;
        foreach (explode('&', $query) as $existing) {
            if (self::nameOf($existing) !== $name) {
                $pairs[] = $existing;
            } elseif (!$replaced) {
                $pairs[] = $pair;
                $replaced = true;
            }
        }
        if ($replaced) {
            return new self($base . '?' . implode('&', $pairs) . $fragment);
        }

        $separator = $query === '' || str_ends_with($query, '&') ? '' : '&';

        return new self($base . '?' . $query . $separator . $pair . $fragment);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function subId(int $position = 1): ?string
    {
        $subId = $this->parameter(self::subIdName($position));

        return $subId === '' ? null : $subId;
    }

    public function erid(): ?string
    {
        $erid = $this->parameter('erid');

        return $erid !== null && AdMarking::isErid($erid) ? $erid : null;
    }

    public function merchantId(): ?MerchantId
    {
        $mid = $this->parameter('mid');
        if ($mid === null || preg_match('/^\d{1,18}\z/', $mid) !== 1 || (int) $mid === 0) {
            return null;
        }

        return new MerchantId((int) $mid);
    }

    public function masked(): string
    {
        return (string) preg_replace('~/ck/[^/?#]+~', '/ck/***', $this->url);
    }

    public function __toString(): string
    {
        return $this->url;
    }

    /**
     * @return array{url: string}
     */
    public function __debugInfo(): array
    {
        return ['url' => $this->masked()];
    }

    private function parameter(string $name): ?string
    {
        $query = $this->parts()[1];
        if ($query === null) {
            return null;
        }
        foreach (explode('&', $query) as $pair) {
            if (self::nameOf($pair) === $name) {
                return explode('=', $pair, 2)[1] ?? '';
            }
        }

        return null;
    }

    /**
     * @return array{string, ?string, string}
     */
    private function parts(): array
    {
        $hash = strpos($this->url, '#');
        $withoutFragment = $hash === false ? $this->url : substr($this->url, 0, $hash);
        $fragment = $hash === false ? '' : substr($this->url, $hash);
        $question = strpos($withoutFragment, '?');
        if ($question === false) {
            return [$withoutFragment, null, $fragment];
        }

        return [substr($withoutFragment, 0, $question), substr($withoutFragment, $question + 1), $fragment];
    }

    private static function nameOf(string $pair): string
    {
        return explode('=', $pair, 2)[0];
    }

    private static function subIdName(int $position): string
    {
        if ($position < 1 || $position > SubId::MAX_POSITION) {
            throw new InvalidArgumentException(sprintf('Номер sub_id — от 1 до %d', SubId::MAX_POSITION));
        }

        return $position === 1 ? 'sub_id' : 'sub_id' . $position;
    }
}
