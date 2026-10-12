<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Catalog;

enum PictureSource: string
{
    case Original = 'original_picture';
    case Picture = 'picture';
    case Thumbnail = 'thumbnail';

    public function isPreferredTo(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Original => 0,
            self::Picture => 1,
            self::Thumbnail => 2,
        };
    }
}
