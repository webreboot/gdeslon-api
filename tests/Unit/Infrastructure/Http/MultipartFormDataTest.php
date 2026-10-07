<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Infrastructure\Http\MultipartFormData;

final class MultipartFormDataTest extends TestCase
{
    public function testExactBytes(): void
    {
        $form = (new MultipartFormData('XyZ'))
            ->withField('order_id', 'GS123L')
            ->withField('description', 'Заказ с сайта')
            ->withFile('attachment', 'scan.pdf', 'application/pdf', "%PDF-1.4\r\n\0\xff");

        self::assertSame('multipart/form-data; boundary=XyZ', $form->contentType());
        self::assertSame(
            "--XyZ\r\nContent-Disposition: form-data; name=\"order_id\"\r\n\r\nGS123L\r\n"
            . "--XyZ\r\nContent-Disposition: form-data; name=\"description\"\r\n\r\nЗаказ с сайта\r\n"
            . "--XyZ\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"scan.pdf\"\r\nContent-Type: application/pdf\r\n\r\n"
            . "%PDF-1.4\r\n\0\xff\r\n"
            . "--XyZ--\r\n",
            $form->body(),
        );
    }

    public function testAllBytesArePassedThrough(): void
    {
        $bytes = implode('', array_map('chr', range(0, 255)));
        $body = (new MultipartFormData('XyZ'))->withFile('f', 'a.png', 'image/png', $bytes)->body();

        self::assertStringContainsString("\r\n\r\n" . $bytes . "\r\n--XyZ--\r\n", $body);
    }

    public function testBoundaryInsideContentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MultipartFormData('XyZ'))->withField('a', "text --XyZ text")->body();
    }

    public function testGeneratedBoundaryAvoidsCollision(): void
    {
        $boundaries = ['XyZ', 'Abc'];
        $form = MultipartFormData::create(static function () use (&$boundaries): string {
            return (string) array_shift($boundaries);
        })->withField('a', 'contains XyZ');

        self::assertSame('multipart/form-data; boundary=Abc', $form->contentType());
        self::assertStringStartsWith('--Abc', $form->body());

        $random = MultipartFormData::create()->withField('a', 'b');
        self::assertMatchesRegularExpression('~^multipart/form-data; boundary=----GdeSlon[0-9a-f]{32}$~', $random->contentType());
    }

    public function testUnsafeNamesAreRejected(): void
    {
        $form = new MultipartFormData('XyZ');
        foreach ([
            static fn (): MultipartFormData => $form->withField('a"b', 'x'),
            static fn (): MultipartFormData => $form->withField("a\r\nb", 'x'),
            static fn (): MultipartFormData => $form->withField('имя', 'x'),
            static fn (): MultipartFormData => $form->withFile('f', "scan\".pdf", 'application/pdf', 'x'),
            static fn (): MultipartFormData => $form->withFile('f', "scan\n.pdf", 'application/pdf', 'x'),
            static fn (): MultipartFormData => $form->withFile('f', 'scan.pdf', "application/pdf\r\nX: 1", 'x'),
        ] as $add) {
            try {
                $add();
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTrailingLineBreaksAreRejected(): void
    {
        foreach ([
            static fn () => (new MultipartFormData('XyZ'))->withField("order_id\n", 'x'),
            static fn () => (new MultipartFormData('XyZ'))->withFile('f', "a.pdf\n", 'application/pdf', 'x'),
            static fn () => (new MultipartFormData('XyZ'))->withFile('f', 'a.pdf', "application/pdf\n", 'x'),
            static fn () => new MultipartFormData("XyZ\n"),
        ] as $create) {
            try {
                $create();
                self::fail('Ожидалось исключение');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
