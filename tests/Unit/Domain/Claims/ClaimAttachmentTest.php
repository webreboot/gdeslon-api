<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Claims;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Claims\AttachmentType;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class ClaimAttachmentTest extends TestCase
{
    public const JPEG = "\xFF\xD8\xFF\xE0jpeg-bytes";
    public const PNG = "\x89PNG\r\n\x1A\npng-bytes";
    public const PDF = "%PDF-1.4\nчек";

    #[DataProvider('validFiles')]
    public function testTypeBySignature(string $fileName, string $contents, AttachmentType $type, string $mediaType, string $expectedName): void
    {
        $attachment = ClaimAttachment::fromContents($fileName, $contents);

        self::assertSame($type, $attachment->type());
        self::assertSame($mediaType, $attachment->type()->mediaType());
        self::assertSame($expectedName, $attachment->fileName());
        self::assertSame($contents, $attachment->contents());
        self::assertSame(strlen($contents), $attachment->size());
    }

    /**
     * @return iterable<string, array{string, string, AttachmentType, string, string}>
     */
    public static function validFiles(): iterable
    {
        yield 'jpg' => ['scan.jpg', self::JPEG, AttachmentType::Jpeg, 'image/jpeg', 'scan.jpg'];
        yield 'JPEG' => ['scan.JPEG', self::JPEG, AttachmentType::Jpeg, 'image/jpeg', 'scan.jpeg'];
        yield 'png' => ['receipt.png', self::PNG, AttachmentType::Png, 'image/png', 'receipt.png'];
        yield 'pdf' => ['receipt.pdf', self::PDF, AttachmentType::Pdf, 'application/pdf', 'receipt.pdf'];
    }

    #[DataProvider('invalidFiles')]
    public function testRejected(string $fileName, string $contents): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClaimAttachment::fromContents($fileName, $contents);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'PNG под видом jpg' => ['scan.jpg', self::PNG];
        yield 'gif' => ['scan.gif', "GIF89a..."];
        yield 'heic с PDF-байтами' => ['scan.heic', self::PDF];
        yield 'без расширения' => ['scan', self::PDF];
        yield 'пусто' => ['scan.pdf', ''];
        yield 'неизвестная сигнатура' => ['scan.pdf', 'just text'];
    }

    public function testSizeLimit(): void
    {
        $limit = ClaimAttachment::MAX_SIZE;
        self::assertSame(10485760, $limit);
        self::assertSame($limit, ClaimAttachment::fromContents('big.pdf', str_pad('%PDF-', $limit, 'x'))->size());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('10');
        ClaimAttachment::fromContents('big.pdf', str_pad('%PDF-', $limit + 1, 'x'));
    }

    public function testFileNameIsSanitized(): void
    {
        $cases = [
            'C:\\Users\\x\\scan.pdf' => 'scan.pdf',
            '../../scan.pdf' => 'scan.pdf',
            'чек.pdf' => 'attachment.pdf',
            "a\"b\r\n.pdf" => 'a_b__.pdf',
            'мой чек 1.pdf' => '________1.pdf',
            "bad\xff.pdf" => 'bad_.pdf',
        ];
        foreach ($cases as $input => $expected) {
            self::assertSame($expected, ClaimAttachment::fromContents($input, self::PDF)->fileName(), $input);
        }

        $long = ClaimAttachment::fromContents(str_repeat('a', 300) . '.pdf', self::PDF)->fileName();
        self::assertLessThanOrEqual(100, strlen($long));
        self::assertStringEndsWith('.pdf', $long);
    }

    public function testContentsAreHiddenFromDumps(): void
    {
        $attachment = ClaimAttachment::fromContents('receipt.pdf', self::PDF . 'SECRET-RECEIPT-DATA');

        ob_start();
        var_dump($attachment);
        $dump = (string) ob_get_clean() . print_r($attachment, true);

        self::assertStringNotContainsString('SECRET-RECEIPT-DATA', $dump);
        self::assertStringContainsString('receipt.pdf', $dump);
    }
}
