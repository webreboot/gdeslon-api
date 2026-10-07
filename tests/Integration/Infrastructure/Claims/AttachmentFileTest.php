<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Claims;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Claims\AttachmentType;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Claims\AttachmentFile;

final class AttachmentFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gdeslon-attachment-' . bin2hex(random_bytes(4)) . '/private-folder';
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir($this->dir);
        rmdir(dirname($this->dir));
    }

    public function testLoadsFile(): void
    {
        file_put_contents($this->dir . '/receipt.pdf', "%PDF-1.4\nчек");

        $attachment = AttachmentFile::load($this->dir . '/receipt.pdf');

        self::assertSame(AttachmentType::Pdf, $attachment->type());
        self::assertSame('receipt.pdf', $attachment->fileName());
        self::assertSame("%PDF-1.4\nчек", $attachment->contents());
    }

    public function testRejected(): void
    {
        $big = $this->dir . '/big.pdf';
        $handle = fopen($big, 'wb');
        self::assertIsResource($handle);
        fwrite($handle, '%PDF-');
        ftruncate($handle, ClaimAttachment::MAX_SIZE + 1); // без записи 10 МиБ на диск
        fclose($handle);

        foreach ([$this->dir . '/missing.pdf', $this->dir, $big] as $path) {
            try {
                AttachmentFile::load($path);
                self::fail('Ожидалось исключение: ' . $path);
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString(basename(dirname($this->dir)), $e->getMessage(), 'локальный путь в сообщение не попадает');
                self::assertStringNotContainsString(sys_get_temp_dir(), $e->getMessage());
            }
        }
    }
}
