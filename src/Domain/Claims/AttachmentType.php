<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

enum AttachmentType: string
{
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Pdf = 'pdf';

    private const SIGNATURES = [
        'jpeg' => "\xFF\xD8\xFF",
        'png' => "\x89PNG\r\n\x1A\n",
        'pdf' => '%PDF-',
    ];

    public static function detect(string $contents): ?self
    {
        foreach (self::SIGNATURES as $type => $signature) {
            if (str_starts_with($contents, $signature)) {
                return self::from($type);
            }
        }

        return null;
    }

    public function mediaType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Pdf => 'application/pdf',
        };
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Jpeg => ['jpg', 'jpeg'],
            self::Png => ['png'],
            self::Pdf => ['pdf'],
        };
    }
}
