<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Claims;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Скан чека для заявки: JPEG, PNG или PDF не больше 10 МиБ (FAQ 74). Тип — по сигнатуре содержимого, расширение имени
 * должно ему соответствовать. Имя файла очищается до безопасного ASCII (без пути, кавычек и переводов строк — оно уходит
 * в заголовок multipart). Содержимое не показывается в var_dump/print_r.
 */
final class ClaimAttachment
{
    /** 10 МиБ; окончательно размер проверяет сервер. */
    public const MAX_SIZE = 10485760;

    private const MAX_FILE_NAME = 100;

    private function __construct(
        private readonly string $fileName,
        private readonly AttachmentType $type,
        private readonly string $contents,
    ) {
    }

    /**
     * @param string $fileName исходное имя (можно с путём) — для расширения и имени в заявке
     * @param string $contents байты файла
     */
    public static function fromContents(string $fileName, string $contents): self
    {
        if ($contents === '') {
            throw new InvalidArgumentException('Вложение пустое');
        }
        if (strlen($contents) > self::MAX_SIZE) {
            throw new InvalidArgumentException(sprintf('Вложение больше 10 МиБ (%d байт)', strlen($contents)));
        }
        $type = AttachmentType::detect($contents)
            ?? throw new InvalidArgumentException('Вложение должно быть JPEG, PNG или PDF (определяется по содержимому)');

        $baseName = (string) preg_replace('~^.*[/\\\\]~s', '', $fileName);
        $dot = strrpos($baseName, '.');
        $extension = $dot === false ? '' : strtolower(substr($baseName, $dot + 1));
        if (!in_array($extension, $type->extensions(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Расширение файла вложения должно быть %s — по содержимому это %s',
                implode(' или ', $type->extensions()),
                strtoupper($type->value),
            ));
        }

        // по символу на символ (UTF-8), для битой строки — по байту
        $stem = substr($baseName, 0, (int) $dot);
        $stem = (string) (preg_replace('/[^A-Za-z0-9._-]/u', '_', $stem) ?? preg_replace('/[^A-Za-z0-9._-]/', '_', $stem));
        if (preg_match('/[A-Za-z0-9]/', $stem) !== 1) {
            $stem = 'attachment';
        }
        $stem = substr($stem, 0, self::MAX_FILE_NAME - strlen($extension) - 1);

        return new self($stem . '.' . $extension, $type, $contents);
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function type(): AttachmentType
    {
        return $this->type;
    }

    public function contents(): string
    {
        return $this->contents;
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['fileName' => $this->fileName, 'type' => $this->type->value, 'size' => $this->size()];
    }
}
