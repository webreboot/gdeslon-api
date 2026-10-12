<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

use Webreboot\GdeSlon\Domain\Shared\HttpUrl;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class Picture
{
    public function __construct(
        private readonly string $url,
        private readonly PictureSource $source,
    ) {
        if (!HttpUrl::isValid($url)) {
            throw new InvalidArgumentException(sprintf('Адрес картинки (%s) — ожидался адрес http(s)://', $source->value));
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    public function source(): PictureSource
    {
        return $this->source;
    }
}
