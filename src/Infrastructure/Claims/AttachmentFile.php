<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Claims;

use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class AttachmentFile
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function load(string $path): ClaimAttachment
    {
        $name = basename(str_replace('\\', '/', $path));
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Файл вложения «%s» не найден или не читается', $name));
        }
        $size = filesize($path);
        if ($size === false || $size > ClaimAttachment::MAX_SIZE) {
            throw new InvalidArgumentException(sprintf('Файл вложения «%s» больше 10 МиБ', $name));
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Файл вложения «%s» не читается', $name));
        }

        return ClaimAttachment::fromContents($name, $contents);
    }
}
