<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Yml;

use Webreboot\GdeSlon\Exception\FileException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Exception\UnsupportedFormatException;

/** @internal */
final class ZipEntryExtractor
{
    public const DEFAULT_MAX_BYTES = 2 * 1024 * 1024 * 1024;

    private const STORED = 0;

    private const DEFLATED = 8;

    // deflate сжимает до ~1000:1, поэтому вход блоками по 8 КиБ держит выход шага в пределах ~8 МиБ
    private const CHUNK = 8192;

    private const STALE_PART_SECONDS = 3600;

    private const EOCD_SIZE = 22;

    private const MAX_COMMENT = 65535;

    private readonly bool $zlibAvailable;

    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        ?bool $zlibAvailable = null,
    ) {
        $this->zlibAvailable = $zlibAvailable ?? extension_loaded('zlib');
    }

    public function extract(string $zipPath, string $targetPath): int
    {
        if (str_contains($zipPath, '://') || !is_file($zipPath) || !is_readable($zipPath)) {
            throw new InvalidArgumentException('Архив выгрузки: ожидался путь к локальному файлу');
        }
        if (str_contains($targetPath, '://')) {
            throw new InvalidArgumentException('Файл выгрузки: ожидался путь к локальному файлу');
        }

        $zip = fopen($zipPath, 'rb');
        if ($zip === false) {
            throw new FileException('Не удалось открыть архив выгрузки');
        }

        try {
            $entry = $this->entry($zip, (int) filesize($zipPath));

            return $this->write($zip, $entry, $targetPath);
        } finally {
            fclose($zip);
        }
    }

    /**
     * @param resource $zip
     *
     * @return array{method: int, crc: int, csize: int, usize: int, offset: int}
     */
    private function entry($zip, int $size): array
    {
        $tailSize = min($size, self::EOCD_SIZE + self::MAX_COMMENT);
        $tail = self::readAt($zip, $size - $tailSize, $tailSize);
        $position = strrpos($tail, "PK\x05\x06");
        if ($position === false || strlen($tail) - $position < self::EOCD_SIZE) {
            throw self::damaged();
        }
        /** @var array{entries: int, cdSize: int, cdOffset: int} $eocd */
        $eocd = unpack('ventries/VcdSize/VcdOffset', $tail, $position + 10);
        if ($eocd['entries'] === 0xFFFF || $eocd['cdOffset'] === 0xFFFFFFFF) {
            throw new UnsupportedFormatException('Архив выгрузки: формат zip64 не поддерживается');
        }
        if ($eocd['entries'] !== 1) {
            throw new UnexpectedResponseException(sprintf('Архив выгрузки: в архиве %s, ожидалась одна', self::records($eocd['entries'])));
        }

        $central = self::readAt($zip, $eocd['cdOffset'], 46);
        if (strlen($central) < 46 || !str_starts_with($central, "PK\x01\x02")) {
            throw self::damaged();
        }
        /** @var array{flags: int, method: int, crc: int, csize: int, usize: int, offset: int} $header */
        $header = unpack('vflags/vmethod/x4/Vcrc/Vcsize/Vusize/x14/Voffset', $central, 8);
        if (($header['flags'] & 1) !== 0) {
            throw new UnsupportedFormatException('Архив выгрузки: зашифрованный архив не поддерживается');
        }
        if ($header['csize'] === 0xFFFFFFFF || $header['usize'] === 0xFFFFFFFF || $header['offset'] === 0xFFFFFFFF) {
            throw new UnsupportedFormatException('Архив выгрузки: формат zip64 не поддерживается');
        }
        if (!in_array($header['method'], [self::STORED, self::DEFLATED], true)) {
            throw new UnsupportedFormatException(sprintf('Архив выгрузки: метод сжатия %d не поддерживается, нужен deflate или без сжатия', $header['method']));
        }
        if ($header['method'] === self::DEFLATED && !$this->zlibAvailable) {
            throw new UnsupportedFormatException('Для распаковки выгрузки нужно расширение zlib');
        }
        if ($header['usize'] > $this->maxBytes) {
            throw new UnexpectedResponseException(sprintf('Архив выгрузки: распакованный файл (%d байт) больше лимита %d байт', $header['usize'], $this->maxBytes));
        }

        $local = self::readAt($zip, $header['offset'], 30);
        if (strlen($local) < 30 || !str_starts_with($local, "PK\x03\x04")) {
            throw self::damaged();
        }
        /** @var array{nameLength: int, extraLength: int} $lengths */
        $lengths = unpack('vnameLength/vextraLength', $local, 26);
        $dataOffset = $header['offset'] + 30 + $lengths['nameLength'] + $lengths['extraLength'];
        if ($dataOffset + $header['csize'] > $eocd['cdOffset']) {
            throw self::damaged();
        }

        return ['method' => $header['method'], 'crc' => $header['crc'], 'csize' => $header['csize'], 'usize' => $header['usize'], 'offset' => $dataOffset];
    }

    /**
     * @param resource $zip
     * @param array{method: int, crc: int, csize: int, usize: int, offset: int} $entry
     */
    private function write($zip, array $entry, string $targetPath): int
    {
        [$partPath, $part] = self::createPart($targetPath);

        try {
            $written = $this->inflate($zip, $part, $entry);
            if (!fclose($part)) {
                throw new FileException('Не удалось записать распакованную выгрузку');
            }
            $part = null;
            if (!@rename($partPath, $targetPath)) {
                throw new FileException('Не удалось заменить файл выгрузки распакованным (файл открыт другой программой?)');
            }

            return $written;
        } catch (\Throwable $e) {
            if (is_resource($part)) {
                fclose($part);
            }
            @unlink($partPath);

            throw $e;
        }
    }

    /**
     * @param resource $zip
     * @param resource $part
     * @param array{method: int, crc: int, csize: int, usize: int, offset: int} $entry
     */
    private function inflate($zip, $part, array $entry): int
    {
        $context = $entry['method'] === self::DEFLATED ? inflate_init(ZLIB_ENCODING_RAW) : null;
        if ($context === false) {
            throw new UnsupportedFormatException('Для распаковки выгрузки нужно расширение zlib');
        }
        $hash = hash_init('crc32b');
        $written = 0;
        $remaining = $entry['csize'];
        fseek($zip, $entry['offset']);
        while ($remaining > 0 || $context !== null) {
            $chunk = '';
            if ($remaining > 0) {
                $chunk = (string) fread($zip, min(self::CHUNK, $remaining));
                if ($chunk === '') {
                    throw self::damaged();
                }
                $remaining -= strlen($chunk);
            }
            if ($context !== null) {
                $chunk = @inflate_add($context, $chunk, $remaining > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH);
                if ($chunk === false) {
                    throw new UnexpectedResponseException('Архив выгрузки: архив повреждён, ошибка распаковки');
                }
                if ($remaining === 0) {
                    $context = null;
                }
            }
            $written += strlen($chunk);
            if ($written > $entry['usize']) {
                throw self::sizeMismatch($written, $entry['usize']);
            }
            hash_update($hash, $chunk);
            if ($chunk !== '' && fwrite($part, $chunk) !== strlen($chunk)) {
                throw new FileException('Не удалось записать распакованную выгрузку');
            }
        }

        if ($written !== $entry['usize']) {
            throw self::sizeMismatch($written, $entry['usize']);
        }
        if (hash_final($hash) !== sprintf('%08x', $entry['crc'])) {
            throw new UnexpectedResponseException('Архив выгрузки: контрольная сумма распакованного файла не совпала, архив повреждён');
        }

        return $written;
    }

    /**
     * @return array{string, resource}
     */
    private static function createPart(string $targetPath): array
    {
        $directory = realpath(dirname($targetPath));
        if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
            throw new FileException('Каталог для распакованной выгрузки не существует или недоступен для записи');
        }
        $prefix = basename($targetPath) . '.part';
        self::removeStaleParts($directory, $prefix);
        $partPath = $directory . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
        $part = @fopen($partPath, 'xb');
        if ($part === false) {
            throw new FileException('Не удалось создать файл для распакованной выгрузки');
        }

        return [$partPath, $part];
    }

    private static function removeStaleParts(string $directory, string $prefix): void
    {
        $staleBefore = time() - self::STALE_PART_SECONDS;
        $pattern = '/\A' . preg_quote($prefix, '/') . '[0-9a-f]{12}\z/';
        foreach (@scandir($directory) ?: [] as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (preg_match($pattern, $name) === 1 && is_file($path) && (int) @filemtime($path) < $staleBefore) {
                @unlink($path);
            }
        }
    }

    /**
     * @param resource $zip
     */
    private static function readAt($zip, int $offset, int $length): string
    {
        if ($length <= 0 || fseek($zip, $offset) !== 0) {
            return '';
        }

        return (string) fread($zip, $length);
    }

    private static function records(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;
        $word = match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'запись',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'записи',
            default => 'записей',
        };

        return $count . ' ' . $word;
    }

    private static function damaged(): UnexpectedResponseException
    {
        return new UnexpectedResponseException('Архив выгрузки: не zip-архив или архив обрезан');
    }

    private static function sizeMismatch(int $actual, int $declared): UnexpectedResponseException
    {
        return new UnexpectedResponseException(sprintf('Архив выгрузки: размер распакованного файла (%d) не совпал с заявленным (%d), архив повреждён', $actual, $declared));
    }
}
