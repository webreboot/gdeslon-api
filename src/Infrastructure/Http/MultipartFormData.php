<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Тело multipart/form-data (RFC 7578) без зависимостей: поля и файлы байт-в-байт, строки — CRLF. Неизменяемое.
 *
 * Имена полей и файлов, Content-Type частей — только печатный ASCII без кавычек и обратного слэша (они уходят в
 * заголовки частей). Граница не должна встречаться в содержимом: заданная — исключение, сгенерированная — подбирается
 * заново (до 3 раз).
 *
 * @internal
 */
final class MultipartFormData
{
    private const ATTEMPTS = 3;

    /** @var list<array{name: string, value: string, fileName: ?string, contentType: ?string}> */
    private array $parts = [];

    private ?string $chosen = null;

    /**
     * @param string|\Closure(): string $boundary граница или генератор границ
     */
    public function __construct(private readonly string|\Closure $boundary)
    {
        if (is_string($boundary)) {
            self::assertBoundary($boundary);
        }
    }

    /**
     * Со случайной границей «----GdeSlon<32 hex>».
     *
     * @param (\Closure(): string)|null $generator свой генератор границ (для тестов)
     */
    public static function create(?\Closure $generator = null): self
    {
        return new self($generator ?? static fn (): string => '----GdeSlon' . bin2hex(random_bytes(16)));
    }

    public function withField(string $name, string $value): self
    {
        self::assertHeaderValue($name, 'Имя поля', false);

        return $this->with(['name' => $name, 'value' => $value, 'fileName' => null, 'contentType' => null]);
    }

    public function withFile(string $name, string $fileName, string $contentType, string $contents): self
    {
        self::assertHeaderValue($name, 'Имя поля', false);
        self::assertHeaderValue($fileName, 'Имя файла', true);
        self::assertHeaderValue($contentType, 'Content-Type файла', true);

        return $this->with(['name' => $name, 'value' => $contents, 'fileName' => $fileName, 'contentType' => $contentType]);
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary();
    }

    public function body(): string
    {
        $boundary = $this->boundary();
        $body = '';
        foreach ($this->parts as $part) {
            $body .= '--' . $boundary . "\r\n" . sprintf('Content-Disposition: form-data; name="%s"', $part['name']);
            if ($part['fileName'] !== null) {
                $body .= sprintf('; filename="%s"', $part['fileName']) . "\r\nContent-Type: " . $part['contentType'];
            }
            $body .= "\r\n\r\n" . $part['value'] . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    /**
     * @param array{name: string, value: string, fileName: ?string, contentType: ?string} $part
     */
    private function with(array $part): self
    {
        $copy = clone $this;
        $copy->parts[] = $part;
        $copy->chosen = null;

        return $copy;
    }

    private function boundary(): string
    {
        if ($this->chosen !== null) {
            return $this->chosen;
        }
        if (is_string($this->boundary)) {
            if ($this->collides($this->boundary)) {
                throw new InvalidArgumentException('Граница multipart встречается в содержимом');
            }

            return $this->chosen = $this->boundary;
        }
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $candidate = ($this->boundary)();
            self::assertBoundary($candidate);
            if (!$this->collides($candidate)) {
                return $this->chosen = $candidate;
            }
        }

        throw new InvalidArgumentException('Не удалось подобрать границу multipart, не встречающуюся в содержимом');
    }

    private function collides(string $boundary): bool
    {
        foreach ($this->parts as $part) {
            if (str_contains($part['value'], $boundary)) {
                return true;
            }
        }

        return false;
    }

    private static function assertBoundary(string $boundary): void
    {
        if (preg_match('~^[A-Za-z0-9\'()+_,./:=?-]{1,70}\z~', $boundary) !== 1) {
            throw new InvalidArgumentException('Граница multipart: 1–70 допустимых символов (RFC 2046)');
        }
    }

    private static function assertHeaderValue(string $value, string $what, bool $allowSpace): void
    {
        $pattern = $allowSpace ? '/^[\x20-\x7E]+\z/' : '/^[\x21-\x7E]+\z/';
        if (preg_match($pattern, $value) !== 1 || str_contains($value, '"') || str_contains($value, '\\')) {
            throw new InvalidArgumentException(sprintf('%s: только печатный ASCII без кавычек и обратного слэша', $what));
        }
    }
}
